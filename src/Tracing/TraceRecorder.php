<?php

namespace Serverfy\Sense\Tracing;

use Serverfy\Sense\Client\SenseClient;

/**
 * Holds the trace of whatever is running now (one HTTP request, one queued job, one command
 * or one scheduled task) and collects the spans that happen inside it.
 */
class TraceRecorder
{
    /** @var array<string, mixed>|null */
    protected ?array $trace = null;

    /** @var list<array<string, mixed>> */
    protected array $spans = [];

    protected float $startedAt = 0.0;

    protected bool $sampled = true;

    protected bool $failed = false;

    protected int $dropped = 0;

    protected int $queries = 0;

    protected float $queriesMs = 0.0;

    public function __construct(
        protected SenseClient $client,
        protected int $maxSpans = 300,
    ) {}

    public function isRecording(): bool
    {
        return $this->trace !== null;
    }

    public function traceId(): ?string
    {
        return $this->trace['trace_id'] ?? null;
    }

    /**
     * Open a trace. Anything still open is discarded: a new unit of work has begun.
     *
     * @param  array<string, mixed>  $data  Extra context shown with the trace.
     */
    public function start(string $type, string $name, ?string $parentTraceId = null, array $data = [], ?float $startedAt = null): void
    {
        if (! $this->client->isConfigured() || ! config('sense.apm.traces_enabled', true)) {
            return;
        }

        $this->startedAt = $startedAt ?? microtime(true);
        $this->spans = [];
        $this->failed = false;
        $this->dropped = 0;
        $this->queries = 0;
        $this->queriesMs = 0.0;

        $rate = $this->client->sampleRate();
        $this->sampled = $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);

        $this->trace = [
            'trace_id' => bin2hex(random_bytes(16)),
            'parent_trace_id' => $parentTraceId,
            'context_type' => $type,
            'name' => $name,
            'context_data' => $data,
        ];

        SenseClient::setTraceContext($this->trace['trace_id'], $parentTraceId);
    }

    public function rename(string $name): void
    {
        if ($this->trace !== null) {
            $this->trace['name'] = $name;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addContext(array $data): void
    {
        if ($this->trace !== null) {
            $this->trace['context_data'] = array_merge($this->trace['context_data'] ?? [], $data);
        }
    }

    public function markFailed(): void
    {
        $this->failed = true;
    }

    /**
     * Record something that just finished and took $durationMs.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function span(string $type, string $name, float $durationMs, array $metadata = [], ?string $sql = null, bool $hasError = false): void
    {
        if ($this->trace === null || $this->client->isSending()) {
            return;
        }

        if (in_array($type, ['db', 'sql_query'], true)) {
            $this->queries++;
            $this->queriesMs += $durationMs;
        }

        if ($hasError) {
            $this->failed = true;
        }

        if (count($this->spans) >= $this->maxSpans) {
            $this->dropped++;

            return;
        }

        $elapsedMs = (microtime(true) - $this->startedAt) * 1000;

        $this->spans[] = array_filter([
            'span_type' => $type,
            'name' => mb_strcut($name, 0, 250),
            'duration_ms' => round($durationMs, 2),
            'offset_ms' => round(max(0.0, $elapsedMs - $durationMs), 2),
            'query_sql' => $sql !== null ? mb_strcut($sql, 0, 4000) : null,
            'metadata' => $metadata ?: null,
            'has_error' => $hasError ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Time a block of code as a custom span.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function measure(string $name, callable $callback, string $type = 'custom')
    {
        $start = microtime(true);
        $failed = false;

        try {
            return $callback();
        } catch (\Throwable $e) {
            $failed = true;

            throw $e;
        } finally {
            $this->span($type, $name, (microtime(true) - $start) * 1000, [], null, $failed);
        }
    }

    /**
     * Close the trace and hand it to the client. Failed traces are always kept; the others are
     * kept according to the sampling rate and carry a weight so totals stay correct.
     *
     * @param  array<string, mixed>  $data
     */
    public function finish(?int $statusCode = null, ?string $userIdentifier = null, array $data = []): void
    {
        if ($this->trace === null) {
            return;
        }

        $trace = $this->trace;
        $this->trace = null;
        SenseClient::setTraceContext(null);

        $failed = $this->failed || ($statusCode !== null && $statusCode >= 500);

        if (! $failed && ! $this->sampled) {
            $this->spans = [];

            return;
        }

        $endedAt = microtime(true);
        $rate = $this->client->sampleRate();

        $trace['context_data'] = array_merge($trace['context_data'] ?? [], $data, array_filter([
            'dropped_spans' => $this->dropped ?: null,
        ]));

        $this->client->captureTrace(array_filter(array_merge($trace, [
            'duration_ms' => round(($endedAt - $this->startedAt) * 1000, 2),
            'status_code' => $statusCode,
            'status' => $failed ? 'failed' : 'completed',
            'has_exception' => $failed,
            'user_identifier' => $userIdentifier,
            'queries_count' => $this->queries,
            'queries_duration_ms' => round($this->queriesMs, 2),
            'spans_count' => count($this->spans) + $this->dropped,
            'sample_weight' => $failed || $rate >= 1.0 || $rate <= 0.0 ? 1 : round(1 / $rate, 2),
            'started_at' => self::timestamp($this->startedAt),
            'ended_at' => self::timestamp($endedAt),
            'spans' => $this->spans,
        ]), fn ($value) => $value !== null));

        $this->spans = [];
    }

    /**
     * The last things that happened in the open trace, oldest first: what led up to an error.
     *
     * @return list<array<string, mixed>>
     */
    public function breadcrumbs(int $limit = 30): array
    {
        return array_map(fn (array $span) => array_filter([
            'type' => $span['span_type'],
            'message' => $span['query_sql'] ?? $span['name'],
            'duration_ms' => $span['duration_ms'],
            'at_ms' => $span['offset_ms'],
            'failed' => $span['has_error'] ?? null,
        ], fn ($value) => $value !== null), array_slice($this->spans, -$limit));
    }

    /** Drop the open trace without sending it. */
    public function discard(): void
    {
        $this->trace = null;
        $this->spans = [];
        SenseClient::setTraceContext(null);
    }

    protected static function timestamp(float $time): string
    {
        return gmdate('Y-m-d\TH:i:s', (int) $time).sprintf('.%03dZ', (int) (($time - floor($time)) * 1000));
    }
}
