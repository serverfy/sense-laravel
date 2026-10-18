<?php

namespace Serverfy\Sense\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Serverfy\Sense\Client\SenseClient;

class SenseQueryListener
{
    protected SenseClient $client;

    protected float $slowThresholdMs;

    public function __construct(?SenseClient $client = null, ?float $slowThresholdMs = null)
    {
        $this->client = $client ?? app(SenseClient::class);
        $this->slowThresholdMs = $slowThresholdMs ?? (float) config('sense.apm.slow_query_threshold', 100.0);
    }

    public function handle(QueryExecuted $event): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        if ($event->time < $this->slowThresholdMs) {
            return;
        }

        if (str_contains($event->sql, 'telemetry_')) {
            return;
        }

        $sanitizedBindings = array_map(function ($binding) {
            if (is_numeric($binding)) {
                return $binding;
            }
            if (is_string($binding)) {
                return strlen($binding) > 50 ? substr($binding, 0, 47).'...' : $binding;
            }

            return gettype($binding);
        }, $event->bindings);

        $this->client->captureSpan(
            type: 'sql_query',
            name: substr($event->sql, 0, 120),
            durationMs: (float) $event->time,
            meta: [
                'sql' => $this->client->scrubSql($event->sql),
                'connection' => $event->connectionName,
                'bindings' => $this->client->scrubPii($sanitizedBindings),
            ]
        );
    }
}
