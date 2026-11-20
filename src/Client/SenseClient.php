<?php

namespace Serverfy\Sense\Client;

use Illuminate\Support\Facades\Http;
use Serverfy\Sense\Support\ExceptionPayload;
use Throwable;

/**
 * Collects telemetry during a request, job or command and sends it in one batch at the end.
 *
 * Nothing here may ever break the host application: every transport failure is swallowed.
 */
class SenseClient
{
    /** Items per kind kept in memory before a flush is forced. */
    public const DEFAULT_BATCH_SIZE = 100;

    /** Largest JSON body sent in one request. The ingest endpoint refuses bodies over 1 MiB. */
    public const MAX_PAYLOAD_BYTES = 900_000;

    protected string $endpoint;

    protected ?string $key;

    protected int $timeout;

    protected ?string $socketPath;

    protected string $udpHost;

    protected int $udpPort;

    protected float $sampleRate;

    /** @var array{exceptions: list<array<string, mixed>>, logs: list<array<string, mixed>>, spans: list<array<string, mixed>>, traces: list<array<string, mixed>>} */
    protected array $buffer = ['exceptions' => [], 'logs' => [], 'spans' => [], 'traces' => []];

    /** True while a batch is on the wire, so the client never instruments its own traffic. */
    protected bool $sending = false;

    /**
     * @var array{trace_id: ?string, parent_trace_id: ?string}
     */
    protected static array $traceContext = [
        'trace_id' => null,
        'parent_trace_id' => null,
    ];

    public function __construct(
        ?string $dsn = null,
        int $timeout = 2,
        ?string $socketPath = null,
        float $sampleRate = 1.0,
        ?string $udpHost = null,
        ?int $udpPort = null
    ) {
        $dsn = $dsn ?? (string) config('sense.dsn', '');
        $this->timeout = $timeout ?: (int) config('sense.timeout', 2);
        $this->socketPath = $socketPath ?? (config('sense.socket_path') ?: null);
        $this->udpHost = $udpHost ?? (string) (config('sense.udp_host') ?: '127.0.0.1');
        $this->udpPort = $udpPort ?? (int) config('sense.udp_port', 0);
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->parseDsn($dsn);
    }

    protected function parseDsn(string $dsn): void
    {
        if (empty($dsn)) {
            $this->endpoint = '';
            $this->key = null;

            return;
        }

        $parts = parse_url($dsn);
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/api/v1/telemetry';

        $this->endpoint = "{$scheme}://{$host}{$port}{$path}";
        $this->key = $query['key'] ?? null;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->endpoint) && ! empty($this->key);
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function sampleRate(): float
    {
        return $this->sampleRate;
    }

    /** True while the client is sending a batch; instrumentation must stay quiet meanwhile. */
    public function isSending(): bool
    {
        return $this->sending;
    }

    public static function setTraceContext(?string $traceId, ?string $parentTraceId = null): void
    {
        static::$traceContext = [
            'trace_id' => $traceId,
            'parent_trace_id' => $parentTraceId,
        ];
    }

    /**
     * @return array{trace_id: ?string, parent_trace_id: ?string}
     */
    public static function getTraceContext(): array
    {
        return static::$traceContext;
    }

    /**
     * Queue an exception for delivery. Exceptions are never sampled out.
     *
     * @param  array<string, mixed>  $context  Extra keys merged into the item (request, user, severity...).
     */
    public function captureException(Throwable $e, array $context = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $item = array_merge(ExceptionPayload::from($e), $this->scrubPii($context));
            $item['trace_id'] ??= static::$traceContext['trace_id'];
        } catch (Throwable) {
            return false;
        }

        return $this->push('exceptions', $item);
    }

    /**
     * Queue a single log line.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function captureLog(string $level, string $message, array $context = [], array $extra = [], ?string $channel = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->push('logs', [
            'level' => strtolower($level),
            'channel' => $channel ?? 'laravel',
            'message' => $message,
            'context' => $this->scrubPii($this->plain($context)),
            'extra' => $this->scrubPii($this->plain($extra)),
            'environment' => $this->environment(),
            'logged_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Queue a standalone performance measurement (something timed outside of a trace).
     *
     * @param  array<string, mixed>  $meta
     */
    public function captureSpan(string $type, string $name, float $durationMs, array $meta = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $meta = $this->scrubPii($meta);

        return $this->push('spans', array_filter([
            'type' => $type,
            'name' => $name,
            'duration_ms' => $durationMs,
            'status_code' => isset($meta['status_code']) ? (int) $meta['status_code'] : null,
            'query_sql' => isset($meta['sql']) ? (string) $meta['sql'] : null,
            'metadata' => $meta,
            'environment' => $this->environment(),
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], fn ($value) => $value !== null));
    }

    /**
     * Queue a full trace with its spans.
     *
     * @param  array<string, mixed>  $trace
     */
    public function captureTrace(array $trace): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // Older callers named the trace with "context_name".
        if (! isset($trace['name']) && isset($trace['context_name'])) {
            $trace['name'] = $trace['context_name'];
        }
        unset($trace['context_name']);

        if (isset($trace['context_type'])) {
            $trace['context_type'] = strtolower((string) $trace['context_type']);
        }

        $trace['environment'] ??= $this->environment();

        if (isset($trace['context_data']) && is_array($trace['context_data'])) {
            $trace['context_data'] = $this->scrubPii($trace['context_data']);
        }

        if (isset($trace['spans']) && is_array($trace['spans'])) {
            foreach ($trace['spans'] as &$span) {
                if (isset($span['query_sql'])) {
                    $span['query_sql'] = $this->scrubSql((string) $span['query_sql']);
                }
                if (isset($span['metadata']) && is_array($span['metadata'])) {
                    $span['metadata'] = $this->scrubPii($span['metadata']);
                }
            }
            unset($span);
        }

        return $this->push('traces', $trace);
    }

    /**
     * Scrub PII and sensitive keys recursively.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrubPii(array $data): array
    {
        $sensitivePattern = '/password|passwd|token|secret|key|authorization|bearer|cookie|card|cvv|ssn|signature/i';

        foreach ($data as $key => $val) {
            if (is_string($key) && preg_match($sensitivePattern, $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($val)) {
                $data[$key] = $this->scrubPii($val);
            }
        }

        return $data;
    }

    /**
     * Redact SQL literals and long numbers.
     */
    public function scrubSql(string $sql): string
    {
        $sanitized = preg_replace("/'([^'\\\\]|\\\\.)*'/", '?', $sql);

        return preg_replace('/\b\d{4,}\b/', '?', $sanitized ?? $sql) ?? $sql;
    }

    /**
     * Send everything collected so far. Safe to call at any time, any number of times.
     */
    public function flush(): bool
    {
        if ($this->sending || ! $this->isConfigured()) {
            return false;
        }

        $buffer = array_filter($this->buffer);
        $this->buffer = ['exceptions' => [], 'logs' => [], 'spans' => [], 'traces' => []];

        if ($buffer === []) {
            return true;
        }

        $delivered = true;

        foreach ($this->chunk($buffer) as $payload) {
            $delivered = $this->dispatch($payload) && $delivered;
        }

        return $delivered;
    }

    /** How many items are waiting to be sent. */
    public function pending(): int
    {
        return array_sum(array_map('count', $this->buffer));
    }

    /**
     * Dispatch one payload: local agent socket, then loopback UDP, then HTTP.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(array $payload): bool
    {
        if ($this->sending) {
            return false;
        }

        $this->sending = true;

        try {
            return $this->transport($payload);
        } catch (Throwable) {
            return false;
        } finally {
            $this->sending = false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function transport(array $payload): bool
    {
        $encoded = json_encode(
            array_merge(['key' => $this->key], $payload),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        if (! is_string($encoded)) {
            return false;
        }

        // 1. Local agent over a Unix domain socket
        if (! empty($this->socketPath) && file_exists($this->socketPath)) {
            $fp = @stream_socket_client("unix://{$this->socketPath}", $errno, $errstr, 0.05);
            if ($fp) {
                $written = @fwrite($fp, $encoded);
                fclose($fp);

                if ($written === strlen($encoded)) {
                    return true;
                }
            }
        }

        // 2. Local agent over loopback UDP (one datagram at most)
        if ($this->udpPort > 0 && strlen($encoded) <= 65507) {
            $fp = @stream_socket_client("udp://{$this->udpHost}:{$this->udpPort}", $errno, $errstr, 0.05);
            if ($fp) {
                $written = @fwrite($fp, $encoded);
                fclose($fp);

                if ($written === strlen($encoded)) {
                    return true;
                }
            }
        }

        // 3. HTTPS straight to the ingest endpoint
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            return false;
        }

        $headers = [
            'X-Serverfy-Key' => (string) $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'Serverfy-Sense-SDK/2.0',
        ];

        if (strlen($body) > 2048 && function_exists('gzencode') && ($gz = gzencode($body, 6)) !== false) {
            $body = $gz;
            $headers['Content-Encoding'] = 'gzip';
        }

        return Http::timeout($this->timeout)
            ->connectTimeout(1)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->endpoint)
            ->successful();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function push(string $kind, array $item): bool
    {
        $this->buffer[$kind][] = $item;

        if (count($this->buffer[$kind]) >= (int) config('sense.batch_size', self::DEFAULT_BATCH_SIZE)) {
            $this->flush();
        }

        return true;
    }

    /**
     * Split a buffer into payloads small enough for the ingest endpoint.
     *
     * @param  array<string, list<array<string, mixed>>>  $buffer
     * @return list<array<string, mixed>>
     */
    protected function chunk(array $buffer): array
    {
        $payloads = [];
        $current = [];
        $size = 0;

        foreach ($buffer as $kind => $items) {
            foreach ($items as $item) {
                $bytes = strlen((string) json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

                // A single item that could never fit is dropped rather than poisoning the batch.
                if ($bytes > self::MAX_PAYLOAD_BYTES) {
                    continue;
                }

                if ($size + $bytes > self::MAX_PAYLOAD_BYTES && $current !== []) {
                    $payloads[] = $current;
                    $current = [];
                    $size = 0;
                }

                $current[$kind][] = $item;
                $size += $bytes;
            }
        }

        if ($current !== []) {
            $payloads[] = $current;
        }

        return $payloads;
    }

    /**
     * Reduce arbitrary log context to JSON-safe scalars and arrays of bounded size.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function plain(array $data, int $depth = 0): array
    {
        $out = [];

        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            if ($value instanceof Throwable) {
                $out[$key] = get_class($value).': '.$value->getMessage();
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 4 ? '[…]' : $this->plain($value, $depth + 1);
            } elseif (is_object($value)) {
                $out[$key] = method_exists($value, '__toString') ? mb_strcut((string) $value, 0, 2000) : get_class($value);
            } elseif (is_string($value)) {
                $out[$key] = mb_strcut($value, 0, 2000);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    protected function environment(): string
    {
        return (string) (config('sense.environment') ?: config('app.env', 'production'));
    }
}
