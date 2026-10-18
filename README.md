# 📡 Serverfy Sense - Laravel SDK

[![Latest Version](https://img.shields.io/badge/version-1.0.0-rose.svg)](https://packagist.org/packages/serverfy/sense)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1%7C%5E8.2%7C%5E8.3%7C%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-10.x%7C11.x%7C12.x%7C13.x-red.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Serverfy Sense** is a zero-overhead Deep APM, Nightwatch-grade Waterfall Tracing, Error Tracking, and SRE bridge designed natively for Laravel 10, 11, 12, and 13.

---

## ⚡ Features

- **Microsecond Ingestion (< 0.1ms)**: Native support for local Unix Domain Sockets (`/run/serverfy/sense.sock`) powered by `serverfy-agent`.
- **Nightwatch-Grade Visual Waterfall Tracing**: Complete anatomy of HTTP requests and Queue background jobs with parent-child correlation.
- **Privacy by Design (LGPD / GDPR)**: Automatic recursive redaction of passwords, tokens, API keys, cookies, and SQL parameter literals (`?`).
- **Sentinel AI Bridge**: Automatically flags slow queries (> 1000ms) and proposes index optimizations for supervised 1-click execution.
- **Resilient Fallback**: Zero-impact non-blocking HTTP fallback if the local daemon socket is offline.

---

## 📦 Installation

Install the package via Composer:

```bash
composer require serverfy/sense
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag="sense-config"
```

---

## ⚙️ Configuration

Add your Data Source Name (DSN) to `.env`:

```env
SERVERFY_SENSE_DSN="https://console.serverfy.io/api/v1/telemetry?key=sfy_live_your_site_key"
SERVERFY_SENSE_SOCKET="/run/serverfy/sense.sock"
SERVERFY_SENSE_APM_ENABLED=true
SERVERFY_SENSE_SLOW_QUERY_THRESHOLD=100
```

---

## 🧪 Testing Connectivity

Run the built-in diagnostic command:

```bash
php artisan sense:test
```

---

## 📖 Usage

### Automatic Tracking
The service provider automatically hooks into:
1. HTTP Middleware (measuring $p50, p95, p99$ response times and status codes).
2. Database Query Listener (capturing queries that exceed `slow_query_threshold`).

### Manual Exception Capture
```php
use Serverfy\Sense\Facades\Sense;

try {
    // business logic
} catch (\Throwable $e) {
    Sense::captureException($e, [
        'user_id' => auth()->id(),
        'order_id' => $order->id,
    ]);
}
```

### Manual Performance Spans
```php
use Serverfy\Sense\Facades\Sense;

Sense::captureSpan(
    type: 'external_api',
    name: 'Stripe API /charges',
    durationMs: 142.5,
    meta: ['status' => 200]
);
```

### Full Waterfall Traces & Queue Correlation
```php
use Serverfy\Sense\Facades\Sense;

// Pass trace context across queue jobs
Sense::setTraceContext($traceId, $parentId);

Sense::captureTrace([
    'trace_id' => $traceId,
    'context_type' => 'HTTP',
    'context_name' => 'POST /api/v1/checkout',
    'duration_ms' => 312.4,
    'spans' => [
        [
            'type' => 'db',
            'name' => 'SELECT * FROM users WHERE id = ?',
            'duration_ms' => 4.2,
            'offset_ms' => 12.0,
        ],
    ],
]);
```

---

## 🛡️ License

The MIT License (MIT). Please see License File for more information.
