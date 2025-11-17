<?php
require __DIR__ . '/../vendor/autoload.php';

use IonTrader\IONPlatform;

$url  = 'broker.iontrader.com:8080';
$name = 'ion-example';
$token = 'your-jwt-auth-token';

$platform = new IONPlatform(
    $url,
    $name,
    [
        'autoSubscribe' => ['EURUSD', 'BTCUSD'],
        'ignoreEvents'  => false,
        'prefix'        => 'ion',
        'mode'          => 'live'
    ],
    null,
    null,
    $token
);

// === EVENTS ===
$platform->on('quote', function ($q) {
    printf("[QUOTE] %s: %.5f / %.5f\n", $q->symbol, $q->bid, $q->ask);
});

$platform->on('quote:EURUSD', function ($q) {
    printf("[EURUSD] Bid: %.5f\n", $q->bid);
});

$platform->on('notify', function ($n) {
    $level = [10 => 'INFO', 20 => 'WARN', 30 => 'ERROR', 40 => 'PROMO'][$n->level] ?? $n->level;
    printf("[NOTIFY:%s] %s\n", $level, $n->message);
});

$platform->on('trade:event', function ($e) {
    $d = $e->data;
    $cmd = $d->cmd === 0 ? 'BUY' : ($d->cmd === 1 ? 'SELL' : 'UNKNOWN');
    printf("[TRADE #%d] %s %.2f %s @ %.5f (P&L: %.2f)\n",
        $d->order, $cmd, $d->volume, $d->symbol, $d->open_price, $d->profit);
});

$platform->on('balance:event', function ($e) {
    $d = $e->data;
    printf("[BALANCE] %s | Balance: %.2f | Equity: %.2f | Margin: %.2f%%\n",
        $d->login, $d->balance, $d->equity, $d->margin_level);
});

$platform->on('user:event', function ($e) {
    $d = $e->data;
    printf("[USER] %s | %s | Group: %s | Leverage: %d\n",
        $d->login, $d->name, $d->group, $d->leverage);
});

$platform->on('symbols:reindex', function ($list) {
    printf("[REINDEX] %d symbols updated\n", count($list));
});

// === COMMANDS ===
$platform->loop->addTimer(2.0, function () use ($platform) {
    if (!$platform->connected) {
        echo "Not connected\n";
        return;
    }

    // Subscribe
    $platform->subscribe('GBPUSD')
        ->then(fn() => print("Subscribed to GBPUSD\n"))
        ->otherwise(fn($e) => printf("Subscribe error: %s\n", $e->getMessage()));

    // AddUser
    $platform->AddUser([
        'group'     => 'TestGroup',
        'name'      => 'John Doe',
        'password'  => 'pass123',
        'leverage'  => 100,
        'enable'    => 1,
        'email'     => 'john@example134412.com'
    ])
        ->then(function ($user) {
            echo "User created:\n";
            print_r($user);
        })
        ->otherwise(fn($e) => printf("AddUser error: %s\n", $e->getMessage()));

    // Unsubscribe after 10s
    $platform->loop->addTimer(10.0, function () use ($platform) {
        $platform->unsubscribe('BTCUSD')
            ->then(fn() => print("Unsubscribed from BTCUSD\n"));
    });

    // Shutdown after 30s
    $platform->loop->addTimer(30.0, function () use ($platform) {
        echo "Shutting down...\n";
        $platform->destroy();
    });
});

$platform->loop->run();