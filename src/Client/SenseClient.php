<?php

namespace Serverfy\Sense\Client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SenseClient
{
    protected string $endpoint;

    protected ?string $key;

    protected int $timeout;

    protected ?string $socketPath;

    protected string $udpHost;

    protected int $udpPort;

    protected float $sampleRate;

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
        $dsn = $dsn ?? (string) config('sense.dsn', env('SERVERFY_SENSE_DSN', ''));
        $this->timeout = $timeout ?: (int) config('sense.timeout', 2);
        $this->socketPath = $socketPath ?? (config('sense.socket_path') ?: env('SERVERFY_SENSE_SOCKET'));
        $this->udpHost = $udpHost ?? (string) (config('sense.udp_host') ?: env('SERVERFY_SENSE_UDP_HOST', '127.0.0.1'));
        $this->udpPort = $udpPort ?? (int) (config('sense.udp_port') ?: env('SERVERFY_SENSE_UDP_PORT', 0));
        $this->sampleRate = $sampleRate ?: (float) config('sense.sample_rate', 1.0);
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
     * Send an exception to Serverfy Sense.
     *
     * @param  array<string, mixed>  $context
     */
    public function captureException(Throwable $e, array $context = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 20) as $trace) {
            $frames[] = [
                'file' => $trace['file'] ?? '[internal]',
                'line' => $trace['line'] ?? 0,
                'function' => ($trace['class'] ?? '').($trace['type'] ?? '').$trace['function'],
            ];
        }

        $payload = [
            'events' => [
                array_merge([
                    'title' => get_class($e).': '.$e->getMessage(),
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'severity' => 'error',
                    'stack_trace' => $frames,
                    'timestamp' => now()->toIso8601String(),
                ], $this->scrubPii($context)),
            ],
        ];

        return $this->dispatch($payload);
    }

    /**
     * Send a single log line to Serverfy Sense.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function captureLog(string $level, string $message, array $context = [], array $extra = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = [
            'logs' => [
                [
                    'level' => strtolower($level),
                    'message' => $message,
                    'context' => $this->scrubPii($context),
                    'extra' => $this->scrubPii($extra),
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        return $this->dispatch($payload);
    }

    /**
     * Send an APM performance span.
     *
     * @param  array<string, mixed>  $meta
     */
    public function captureSpan(string $type, string $name, float $durationMs, array $meta = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = [
            'spans' => [
                [
                    'type' => $type,
                    'name' => $name,
                    'duration_ms' => $durationMs,
                    'meta' => $this->scrubPii($meta),
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        return $this->dispatch($payload);
    }

    /**
     * Send a full waterfall trace with nested spans to Serverfy Sense.
     *
     * @param  array<string, mixed>  $trace
     */
    public function captureTrace(array $trace): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

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

        $payload = [
            'traces' => [$trace],
        ];

        return $this->dispatch($payload);
    }

    /**
     * Scrub PII and sensitive keys recursively.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scrubPii(array $data): array
    {
        $sensitivePattern = '/password|token|secret|key|authorization|bearer|cookie|card|cvv|ssn/i';

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
     * Redact SQL literals and sensitive parameters.
     */
    public function scrubSql(string $sql): string
    {
        $sanitized = preg_replace("/'([^'\\\\]|\\\\.)*'/", '?', $sql);

        return preg_replace('/\b\d{4,}\b/', '?', $sanitized ?? $sql) ?? $sql;
    }

    /**
     * Dispatch payload via zero-overhead local daemon socket or HTTP.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(array $payload): bool
    {
        // 1. Zero-Overhead Local Daemon Transport (Unix Domain Socket)
        if (! empty($this->socketPath) && file_exists($this->socketPath)) {
            try {
                $fp = @stream_socket_client("unix://{$this->socketPath}", $errno, $errstr, 0.05);
                if ($fp) {
                    $payloadWithKey = array_merge(['key' => $this->key], $payload);
                    fwrite($fp, json_encode($payloadWithKey));
                    fclose($fp);

                    return true;
                }
            } catch (Throwable) {
                // Fall back to UDP or HTTP silently
            }
        }

        // 2. Loopback UDP Ingestion Transport
        if ($this->udpPort > 0) {
            try {
                $fp = @stream_socket_client("udp://{$this->udpHost}:{$this->udpPort}", $errno, $errstr, 0.05);
                if ($fp) {
                    $payloadWithKey = array_merge(['key' => $this->key], $payload);
                    $encoded = json_encode($payloadWithKey);
                    if (is_string($encoded) && strlen($encoded) <= 65507) {
                        fwrite($fp, $encoded);
                        fclose($fp);

                        return true;
                    }
                    fclose($fp);
                }
            } catch (Throwable) {
                // Fall back to HTTP silently
            }
        }

        // 3. HTTP Transport Fallback
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Serverfy-Key' => $this->key,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Serverfy-Sense-SDK/1.0',
                ])
                ->post($this->endpoint, $payload);

            return $response->successful();
        } catch (Throwable $e) {
            Log::debug('Serverfy Sense Client dispatch failed: '.$e->getMessage());

            return false;
        }
    }
}
