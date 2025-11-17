<div align="center">

# ion-server-api-php

**Ultra-low latency PHP TCP client for [IonTrader](https://iontrader.com)**  
Real-time market data, trade execution, balance & user management via TCP.

![Packagist Version](https://img.shields.io/packagist/v/iontrader/server-api-php?color=green)
![PHP](https://img.shields.io/badge/php-%3E%3D8.0-blue)
![License](https://img.shields.io/badge/license-MIT-blue)
![Downloads](https://img.shields.io/packagist/dm/iontrader/server-api-php)

> **Server-to-Server (S2S) integration** — ideal for brokers, CRMs, HFT bots, and back-office systems.

[Documentation](https://iontrader.com/tcp) · [Examples](./examples) · [Report Bug](https://github.com/iontrader/server-api-php/issues)

</div>

---

## Features

| Feature | Description |
|-------|-------------|
| **TCP S2S** | Direct TCP connection — no HTTP overhead |
| **Real-time Events** | Quotes, trades, balance, user & symbol updates |
| **Optimized Subscribe** | `$platform->subscribe()` / `unsubscribe()` |
| **Dynamic Commands** | `$platform->AddUser([])`, `$platform->GetTrades()` |
| **Auto-reconnect** | Robust reconnection with backoff |
| **Event Filtering** | `ignoreEvents`, per-symbol listeners |
| **extID Tracking** | Reliable command responses |
| **JSON Repair** | Handles malformed packets gracefully |

---

## Installation

```bash
composer require iontrader/server-api-php
```

---

## Quick Start

```php
use IonTrader\IONPlatform;

$platform = new IONPlatform(
  'broker.iontrader.com:8080', // Host:port
  'my-trading-bot',
  ['autoSubscribe' => ['EURUSD', 'BTCUSD']],
  null, null,
  'your-jwt-auth-token'
);

// Real-time quotes
$platform->on('quote', function ($q) {
  printf("%s: %.5f/%.5f\n", $q->symbol, $q->bid, $q->ask);
});

// Trade events
$platform->on('trade:event', function ($e) {
  $d = $e->data;
  printf("#%d %s %.2f %s\n", $d->order, $d->cmd === 0 ? 'BUY' : 'SELL', $d->volume, $d->symbol);
});

// Subscribe to new symbol
$platform->subscribe('XAUUSD');

// Create user
$platform->AddUser([
  'name' => 'John Doe',
  'group' => 'VIP',
  'leverage' => 500,
  'email' => 'john@example.com'
])->then(fn($r) => print_r($r));

// Run the event loop
$platform->loop->run();

// Graceful shutdown
$platform->destroy();
```

---

## Supported Events

| Event | Description | Example |
|------|-------------|--------|
| `quote` | Real-time tick | `{ symbol: 'EURUSD', bid: 1.085, ask: 1.086 }` |
| `quote:SYMBOL` | Per-symbol | `quote:EURUSD` |
| `notify` | System alerts | `notify:20` (warning) |
| `trade:event` | Order open/close/modify | `data.order`, `data.profit` |
| `balance:event` | Balance & margin update | `data.equity`, `data.margin_level` |
| `user:event` | User profile change | `data.leverage`, `data.group` |
| `symbol:event` | Symbol settings update | `data.spread`, `data.swap_long` |
| `group:event` | Group config change | `data.default_leverage` |
| `symbols:reindex` | Symbol index map | `[[symbol, sym_index, sort_index], ...]` |
| `security:reindex` | Security group map | `[[sec_index, sort_index], ...]` |

---

### Methods

| Method | Description |
|-------|-------------|
| `subscribe($channels)` | Fast subscribe to symbols |
| `unsubscribe($channels)` | Fast unsubscribe |
| `$platform->CommandName($data)` | Dynamic command (e.g., `AddUser`) |
| `send($payload)` | Legacy format: `{ command, data }` |
| `destroy()` | Close connection |

---

## Examples

### Subscribe & Unsubscribe

```php
$platform->subscribe(['GBPUSD', 'USDJPY']);
$platform->unsubscribe('BTCUSD');
```

### Get All Users

```php
$platform->GetUsers([])->then(fn($users) => print_r($users));
```

### Listen to Balance Changes

```php
$platform->on('balance:event', function ($e) {
  printf("User %s: Equity = %.2f\n", $e->data->login, $e->data->equity);
});
```

### Full Example

See [`examples/console.php`](examples/console.php)

---

## Configuration

| Option | Type | Default | Description |
|-------|------|---------|-------------|
| `autoSubscribe` | `array` | `[]` | Auto-subscribe on connect |
| `ignoreEvents` | `bool` | `false` | Disable all event emission |
| `mode` | `'live' \| 'demo'` | `'live'` | Environment mode |

---

## Documentation

- **TCP API**: [https://iontrader.com/tcp](https://iontrader.com/tcp)
- **Client API**: [https://iontrader.com/client-api](https://iontrader.com/client-api)
- **FIX API**: [https://iontrader.com/fix-api](https://iontrader.com/fix-api)

---

## Requirements

- PHP **v8.0 or higher**
- Valid **IonTrader JWT token**
- **reactphp/socket** (installed via Composer)

---

## License

Distributed under the **MIT License**.  
See [`LICENSE`](LICENSE) for more information.

---

<div align="center">

**Made with passion for high-frequency trading**

[iontrader.com](https://iontrader.com) · [GitHub](https://github.com/iontrader/server-api-php)

</div>