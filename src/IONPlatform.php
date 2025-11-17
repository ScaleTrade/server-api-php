<?php

namespace IonTrader;

use Exception;
use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Promise\Deferred;

class IONPlatform
{
    private string $url;
    private string $name;
    private array $options;
    private string $token;
    private array $autoSubscribeChannels = [];
    private bool $ignoreEvents = false;
    private string $prefix = 'ion';
    private string $mode = 'live';
    private $broker;
    private $ctx;

    private $socket;
    private $connector;
    private string $buffer = '';
    private array $pending = [];
    private array $seenNotifyTokens = [];

    // Event Emitter
    private array $listeners = [];

    public bool $connected = false;
    public bool $alive = true;
    public $loop;

    public function __construct(
        string $url,
        string $name,
        array $options = [],
               $broker = null,
               $ctx = null,
        string $token = ''
    ) {
        $this->url = $url;
        $this->name = $name;
        $this->options = $options;
        $this->broker = $broker ?? [];
        $this->ctx = $ctx ?? [];
        $this->token = $token;
        $this->autoSubscribeChannels = $options['autoSubscribe'] ?? [];
        $this->ignoreEvents = $options['ignoreEvents'] ?? false;
        $this->prefix = $options['prefix'] ?? 'ion';
        $this->mode = $options['mode'] ?? 'live';

        $this->loop = Loop::get();
        $this->connector = new Connector($this->loop);

        $this->createSocket();
    }

    /**
     * Establish TCP connection and set up event handlers
     */
    private function createSocket(): void
    {
        $this->seenNotifyTokens = [];
        $this->connected = false;
        $this->alive = true;
        $this->buffer = '';

        [$host, $port] = explode(':', $this->url);

        $this->connector->connect("tcp://{$host}:{$port}")->then(
            function ($socket) {
                $this->socket = $socket;
                $this->connected = true;
                echo "[ION:{$this->name}] Connected to {$this->url}\n";

                // Auto-subscribe after delay
                if (!empty($this->autoSubscribeChannels)) {
                    $this->loop->addTimer(0.5, function () {
                        $this->subscribe($this->autoSubscribeChannels)
                            ->then(function () {
                                echo "[ION:{$this->name}] Auto-subscribed: " . implode(', ', $this->autoSubscribeChannels) . "\n";
                            })
                            ->otherwise(function ($err) {
                                echo "[ION:{$this->name}] Auto-subscribe failed: {$err->getMessage()}\n";
                            });
                    });
                }

                $socket->on('data', [$this, 'handleData']);
                $socket->on('close', [$this, 'onClose']);
                $socket->on('error', [$this, 'onError']);
            },
            function ($e) {
                echo "[ION:{$this->name}] Connection failed: {$e->getMessage()}\n";
                if ($this->alive) $this->reconnect();
            }
        );
    }

    public function handleData($data): void
    {
        $this->buffer .= $data;

        $delimiterPos = strrpos($this->buffer, "\r\n");
        if ($delimiterPos === false) return;

        $received = substr($this->buffer, 0, $delimiterPos);
        $this->buffer = substr($this->buffer, $delimiterPos + 2);
        $tokens = explode("\r\n", $received);

        foreach ($tokens as $token) {
            if (trim($token) === '') continue;

            $parsed = $this->parseJson($token);
            if ($parsed === null) continue;

            // === ARRAY MESSAGES ===
            if (is_array($parsed)) {
                $marker = $parsed[0] ?? null;

                // Quote: ["t", symbol, bid, ask, timestamp]
                if ($marker === 't' && count($parsed) >= 4) {
                    [, $symbol, $bid, $ask, $timestamp] = $parsed;
                    if (is_string($symbol) && is_numeric($bid) && is_numeric($ask)) {
                        $quote = (object) [
                            'symbol' => $symbol,
                            'bid' => (float) $bid,
                            'ask' => (float) $ask,
                            'timestamp' => $timestamp ? date('c', (int) $timestamp) : null
                        ];
                        $this->emit('quote', $quote);
                        $this->emit("quote:{strtoupper($symbol)}", $quote);
                    }
                    continue;
                }

                // Notify: ["n", msg, desc, token, status, level, user_id, time, data?, code]
                if ($marker === 'n' && count($parsed) >= 8) {
                    [, $message, $description, $token, $status, $level, $user_id, $create_time, $dataOrCode, $code] = array_pad($parsed, 10, null);
                    $isObject = is_object($dataOrCode);
                    $notify = (object) [
                        'message' => $message,
                        'description' => $description,
                        'token' => $token,
                        'status' => $status,
                        'level' => $level,
                        'user_id' => $user_id,
                        'create_time' => $create_time ? date('c', (int) $create_time) : null,
                        'data' => $isObject ? $dataOrCode : (object) [],
                        'code' => (int) ($isObject ? $code : $dataOrCode)
                    ];
                    if (array_key_exists($token, $this->seenNotifyTokens)) continue;
                    $this->seenNotifyTokens[$token] = true;
                    $this->emit('notify', $notify);
                    $this->emit("notify:{$level}", $notify);
                    continue;
                }

                // Symbols Reindex: ["sr", [[symbol, sym_index, sort_index], ...]]
                if ($marker === 'sr' && count($parsed) === 2) {
                    $this->emit('symbols:reindex', $parsed[1]);
                    continue;
                }

                // Security Reindex: ["sc", [[sec_index, sort_index], ...]]
                if ($marker === 'sc' && count($parsed) === 2) {
                    $this->emit('security:reindex', $parsed[1]);
                    continue;
                }

                echo "[ION:{$this->name}] Unknown array message: " . json_encode($parsed) . "\n";
                continue;
            }

            // === JSON EVENT OBJECTS ===
            if (is_object($parsed) && isset($parsed->event)) {
                $payload = (object) [
                    'type' => $parsed->type ?? null,
                    'data' => $parsed->data ?? null
                ];
                $this->emit($parsed->event, $payload);

                if (isset($payload->data->login)) {
                    $this->emit("{$parsed->event}:{$payload->data->login}", $payload);
                }
                if (isset($payload->data->symbol)) {
                    $this->emit("{$parsed->event}:{$payload->data->symbol}", $payload);
                }
                if (isset($payload->data->group)) {
                    $this->emit("{$parsed->event}:{$payload->data->group}", $payload);
                }
                continue;
            }

            // === COMMAND RESPONSES (extID) ===
            if (is_object($parsed) && isset($parsed->extID)) {
                $extID = $parsed->extID;
                if (isset($this->pending[$extID])) {
                    $this->pending[$extID]->resolve(clone $parsed);
                    unset($this->pending[$extID]);
                }
                continue;
            }

            echo "[ION:{$this->name}] Unknown message: " . json_encode($parsed) . "\n";
        }
    }

    public function onClose(): void
    {
        $this->connected = false;
        echo "[ION:{$this->name}] Connection closed\n";
        if ($this->alive) $this->reconnect();
    }

    public function onError(Exception $err): void
    {
        echo "[ION:{$this->name}] Socket error: {$err->getMessage()}\n";
        if ($this->alive) $this->reconnect();
    }

    private function reconnect(): void
    {
        $this->seenNotifyTokens = [];
        $this->loop->addTimer(4.0, function () {
            echo "[ION:{$this->name}] Reconnecting...\n";
            $this->createSocket();
        });
    }

    private function parseJson(string $input)
    {
        // Simple JSON repair attempts
        $fixed = preg_replace('/(?<!\\\\)\'/', '"', $input); // Single to double quotes
        $fixed = preg_replace('/,(\s*[}\]])/', '$1', $fixed); // Trailing commas
        $fixed = trim(preg_replace('/[\n\r\t]/', '', $fixed));

        $decoded = json_decode($fixed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "[ION:{$this->name}] Parse error: " . json_last_error_msg() . " for: $input\n";
            return null;
        }
        return $decoded;
    }

    /**
     * Emit event if not ignored
     */
    public function emit(string $event, ...$args): void
    {
        if ($this->ignoreEvents) return;

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Register event listener
     */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /**
     * Low-level send
     */
    public function send(object $payload): \React\Promise\PromiseInterface
    {
        if (!$this->connected) {
            return \React\Promise\reject(new Exception("[ION:{$this->name}] Not connected"));
        }

        if (!isset($payload->extID)) {
            $payload->extID = substr(bin2hex(random_bytes(6)), 0, 12);
        }
        $payload->__token = $this->token;

        $deferred = new Deferred();
        $extID = $payload->extID;
        $this->pending[$extID] = $deferred;

        $this->loop->addTimer(30.0, function () use ($extID, $deferred) {
            if (isset($this->pending[$extID])) {
                unset($this->pending[$extID]);
                $deferred->reject(new Exception("[ION:{$this->name}] Timeout for extID: $extID"));
            }
        });

        $json = json_encode($payload) . "\r\n";
        try {
            $this->socket->write($json);
        } catch (Exception $err) {
            $deferred->reject($err);
        }

        return $deferred->promise();
    }

    /**
     * Call command
     */
    public function call(string $command, ?array $data = []): \React\Promise\PromiseInterface
    {
        return $this->send((object) ['command' => $command, 'data' => (object) ($data ?? [])]);
    }

    /**
     * Magic method for dynamic calls
     */
    public function __call(string $method, array $args): \React\Promise\PromiseInterface
    {
        return $this->call($method, $args[0] ?? []);
    }

    /**
     * Subscribe to channels
     */
    public function subscribe(array|string $channels): \React\Promise\PromiseInterface
    {
        $chanels = is_array($channels) ? $channels : [$channels];
        return $this->call('Subscribe', ['chanels' => $chanels]);
    }

    /**
     * Unsubscribe from channels
     */
    public function unsubscribe(array|string $channels): \React\Promise\PromiseInterface
    {
        $chanels = is_array($channels) ? $channels : [$channels];
        return $this->call('Unsubscribe', ['chanels' => $chanels]);
    }

    /**
     * Gracefully close
     */
    public function destroy(): void
    {
        $this->alive = false;
        $this->seenNotifyTokens = [];
        if ($this->socket) {
            $this->socket->close();
        }
        $this->loop->stop();
    }
}