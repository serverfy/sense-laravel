# 📡 Serverfy Sense - Laravel SDK

[![Latest Version](https://img.shields.io/badge/version-2.3.0-rose.svg)](https://packagist.org/packages/serverfy/sense)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1%7C%5E8.2%7C%5E8.3%7C%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-10.x%7C11.x%7C12.x%7C13.x-red.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**Serverfy Sense** records errors, request traces, queries, queued jobs and scheduled tasks of a Laravel 10, 11, 12 or 13 application and sends them to your Serverfy console.

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

Add the DSN of your site (Serverfy console, Site > Sense > Setup) to `.env`:

```env
SERVERFY_SENSE_DSN="https://your-serverfy-host/api/v1/telemetry?key=sfy_live_your_site_key"
```

That is the whole setup. Optional settings:

```env
SERVERFY_SENSE_SAMPLE_RATE=0.2          # store one in five traces; failures are always stored
SERVERFY_SENSE_RELEASE="${GIT_COMMIT}"  # label errors with the deploy they came from
SERVERFY_SENSE_ENVIRONMENT=production
SERVERFY_SENSE_SEND_REQUEST_BODY=false  # request bodies are not sent unless you opt in
```

---

## 🧪 Testing Connectivity

```bash
php artisan sense:test
```

---

## 📖 What is recorded

Once the DSN is set, with no code changes:

| What | How it shows up |
| --- | --- |
| HTTP requests | One trace per request, named after the route pattern (`GET /orders/{order}`) |
| Database queries | Spans inside the trace; slow queries are also reported outside of traces |
| Queued jobs | One trace per job, linked to the request that dispatched it |
| Artisan commands and scheduled tasks | One trace each (long-running workers are skipped) |
| Cache reads and writes, outgoing HTTP calls | Spans inside the current trace |
| Reported exceptions | Stack trace, source lines around the failure, request and user id |

Telemetry is collected in memory and sent in one compressed batch after the response has gone
out, so a request never waits for it. A failure to deliver is silent and never reaches your
application.

### Privacy

Values of keys that look like credentials (`password`, `token`, `secret`, `authorization`,
`cookie`, card data) are replaced before anything leaves the server, SQL is sent with its
placeholders and never with bound values, outgoing HTTP calls are recorded without their query
string, and request bodies are only sent when you opt in.

### Sampling

`SERVERFY_SENSE_SAMPLE_RATE` controls how many traces are stored in full. Failed requests and
jobs are always sent, and every sampled trace carries the weight of the ones left out, so
request counts, error rates and percentiles in Sense stay accurate.

### Application logs

```php
// config/logging.php
'sense' => [
    'driver' => 'monolog',
    'handler' => \Serverfy\Sense\Logging\SenseMonologHandler::class,
    'level' => 'warning',
],
```

```env
LOG_STACK=single,sense
```

### Local agent (optional)

On a busy site, run the agent next to the application and telemetry stops costing a network
call per request: the application hands it over a local socket and the agent forwards
everything in batches.

```bash
php artisan sense:agent
```

Keep it running with Supervisor or systemd, as the same user that runs the application. No
configuration is needed: the SDK finds the agent on `storage/framework/sense.sock` and falls
back to sending directly whenever the agent is not there. `php artisan sense:agent --ping`
tells you whether it is up.

### Your own metrics

```php
use Serverfy\Sense\Facades\Sense;

Sense::metric('orders.paid');                 // count something
Sense::metric('checkout.total_cents', 15990); // or measure it
```

Sense keeps the count, total, average and percentiles of each metric per minute. They show up
in the performance tab, and an alert rule can watch them ("tell me when fewer than 5 orders are
paid in 30 minutes"). Keep names stable and never put ids in them.

### Manual capture

```php
use Serverfy\Sense\Facades\Sense;
use Serverfy\Sense\Tracing\TraceRecorder;

// An exception you caught yourself
Sense::captureException($e, ['user' => ['id' => auth()->id()]]);

// Time a block of code as a span of the current trace
app(TraceRecorder::class)->measure('render invoice pdf', fn () => $pdf->render());
```

---

## 🛡️ License

The MIT License (MIT). Please see License File for more information.
