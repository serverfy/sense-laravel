<?php

namespace Serverfy\Sense\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Serverfy\Sense\Client\SenseClient;
use Throwable;

/**
 * A small daemon that runs next to the application. Requests hand their telemetry to it over
 * a local socket in a fraction of a millisecond, and it forwards everything to Serverfy in
 * batches, so no request ever waits for the network.
 */
class SenseAgentCommand extends Command
{
    /** Kinds of telemetry forwarded. "events" is how version 1 of the SDK named exceptions. */
    public const KINDS = ['exceptions', 'logs', 'spans', 'traces', 'trace_spans', 'metrics'];

    /** Items of one kind sent in a single request. The ingest endpoint keeps at most 500. */
    public const MAX_ITEMS_PER_REQUEST = 400;

    /** Size of one request body before compression. The ingest endpoint refuses more than 1 MiB compressed and 5 MiB expanded. */
    public const MAX_REQUEST_BYTES = 2_000_000;

    /** Frames held in memory while Serverfy cannot be reached. Beyond this the oldest are dropped. */
    public const MAX_BUFFERED_FRAMES = 5000;

    protected $signature = 'sense:agent
                            {--socket= : Path of the Unix socket (defaults to storage/framework/sense.sock)}
                            {--port=0 : Also listen on this loopback UDP port (0 disables it)}
                            {--host=127.0.0.1 : UDP bind address}
                            {--flush-interval=5 : Seconds between deliveries}
                            {--batch-size=50 : Frames that force a delivery before the interval}
                            {--once : Deliver what arrives during one interval and exit (for tests)}
                            {--ping : Check that a running agent answers}';

    protected $description = 'Run the Serverfy Sense local agent, which forwards telemetry in batches';

    /** @var list<array<string, mixed>> */
    public array $buffer = [];

    public float $lastFlushTime = 0.0;

    public bool $running = true;

    public function handle(SenseClient $client): int
    {
        $socketPath = (string) ($this->option('socket') ?: SenseClient::defaultSocketPath());

        if ($this->option('ping')) {
            return $this->ping($socketPath);
        }

        if (! $client->isConfigured()) {
            $this->error('Serverfy Sense is not configured. Set SERVERFY_SENSE_DSN in your .env file.');

            return self::FAILURE;
        }

        $directory = dirname($socketPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Cannot create the socket directory [{$directory}].");

            return self::FAILURE;
        }

        if (file_exists($socketPath)) {
            @unlink($socketPath);
        }

        $unix = @stream_socket_server("unix://{$socketPath}", $errno, $errstr);
        if (! $unix) {
            $this->error("Cannot listen on [{$socketPath}]: {$errstr}");

            return self::FAILURE;
        }

        // Only the user running the application and its group may write to it.
        @chmod($socketPath, 0660);
        stream_set_blocking($unix, false);
        $this->info("Sense agent listening on {$socketPath}");

        $udp = null;
        if ((int) $this->option('port') > 0) {
            $udp = @stream_socket_server('udp://'.$this->option('host').':'.(int) $this->option('port'), $errno, $errstr, STREAM_SERVER_BIND);
            if ($udp) {
                stream_set_blocking($udp, false);
                $this->line('Also listening on UDP '.$this->option('host').':'.(int) $this->option('port'));
            } else {
                $this->warn("UDP listener not started: {$errstr}");
            }
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->running = false;
                });
            }
        }

        $interval = max(1, (int) $this->option('flush-interval'));
        $batchSize = max(1, (int) $this->option('batch-size'));
        $this->lastFlushTime = microtime(true);

        while ($this->running) {
            $read = array_values(array_filter([$unix, $udp]));
            $write = $except = null;

            if (@stream_select($read, $write, $except, 0, 250000) > 0) {
                foreach ($read as $ready) {
                    if ($ready === $unix) {
                        if ($connection = @stream_socket_accept($unix, 0.05)) {
                            stream_set_timeout($connection, 1);
                            $this->receive((string) stream_get_contents($connection, self::MAX_REQUEST_BYTES));
                            fclose($connection);
                        }
                    } elseif (($packet = @stream_socket_recvfrom($ready, 65535)) !== false) {
                        $this->receive((string) $packet);
                    }
                }
            }

            $due = microtime(true) - $this->lastFlushTime >= $interval;

            if (count($this->buffer) >= $batchSize || ($due && $this->buffer !== [])) {
                $this->flush($client);
            }

            if ($due && $this->option('once')) {
                break;
            }
        }

        $this->flush($client);
        fclose($unix);
        @unlink($socketPath);

        if ($udp) {
            fclose($udp);
        }

        return self::SUCCESS;
    }

    /**
     * Keep one frame received from the application.
     */
    public function receive(string $raw): void
    {
        $frame = json_decode($raw, true);

        // A frame names the key it is for. One without a key is not from the SDK.
        if (! is_array($frame) || ! empty($frame['ping']) || empty($frame['key']) || ! is_string($frame['key'])) {
            return;
        }

        $this->buffer[] = $frame;

        if (count($this->buffer) > self::MAX_BUFFERED_FRAMES) {
            array_shift($this->buffer);
        }
    }

    /**
     * Send what is buffered. Frames that could not be delivered go back to the buffer and are
     * tried again on the next interval.
     */
    public function flush(SenseClient $client): void
    {
        $this->lastFlushTime = microtime(true);

        if ($this->buffer === []) {
            return;
        }

        $frames = $this->buffer;
        $this->buffer = [];

        foreach ($this->requests($frames) as $request) {
            if (! $this->deliver($client->getEndpoint(), $request['key'], $request['payload'])) {
                // Keep what failed, as one frame, for the next attempt.
                $this->buffer[] = ['key' => $request['key']] + $request['payload'];
            }
        }

        if (count($this->buffer) > self::MAX_BUFFERED_FRAMES) {
            $this->buffer = array_slice($this->buffer, -self::MAX_BUFFERED_FRAMES);
        }
    }

    /**
     * Merge frames per key and cut the result into requests the ingest endpoint accepts whole.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array{key: string, payload: array<string, list<mixed>>}>
     */
    public function requests(array $frames): array
    {
        $requests = [];
        $open = [];

        $close = function (string $key) use (&$requests, &$open): void {
            if (! empty($open[$key]['payload'])) {
                $requests[] = ['key' => $key, 'payload' => $open[$key]['payload']];
            }

            $open[$key] = ['payload' => [], 'bytes' => 0];
        };

        foreach ($frames as $frame) {
            $key = (string) $frame['key'];
            $open[$key] ??= ['payload' => [], 'bytes' => 0];

            // Version 1 of the SDK sent exceptions under "events".
            if (! empty($frame['events']) && is_array($frame['events'])) {
                $frame['exceptions'] = array_merge((array) ($frame['exceptions'] ?? []), $frame['events']);
            }

            foreach (self::KINDS as $kind) {
                foreach ((array) ($frame[$kind] ?? []) as $item) {
                    $bytes = strlen((string) json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

                    if ($bytes > self::MAX_REQUEST_BYTES) {
                        continue;
                    }

                    if ($open[$key]['bytes'] + $bytes > self::MAX_REQUEST_BYTES
                        || count($open[$key]['payload'][$kind] ?? []) >= self::MAX_ITEMS_PER_REQUEST) {
                        $close($key);
                    }

                    $open[$key]['payload'][$kind][] = $item;
                    $open[$key]['bytes'] += $bytes;
                }
            }
        }

        foreach (array_keys($open) as $key) {
            $close((string) $key);
        }

        return $requests;
    }

    /**
     * @param  array<string, list<mixed>>  $payload
     */
    protected function deliver(string $endpoint, string $key, array $payload): bool
    {
        try {
            $body = (string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers = ['X-Serverfy-Key' => $key, 'Accept' => 'application/json', 'User-Agent' => 'Serverfy-Sense-Agent/2.3'];

            if (function_exists('gzencode') && ($compressed = gzencode($body, 6)) !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }

            $response = Http::timeout(5)->connectTimeout(2)->withHeaders($headers)->withBody($body, 'application/json')->post($endpoint);

            // A refusal that will not change on retry (bad key, malformed batch) is dropped;
            // anything else is worth another attempt.
            return $response->successful() || in_array($response->status(), [400, 401, 403, 413, 422], true);
        } catch (Throwable) {
            return false;
        }
    }

    protected function ping(string $socketPath): int
    {
        $connection = file_exists($socketPath) ? @stream_socket_client("unix://{$socketPath}", $errno, $errstr, 0.5) : false;

        if (! $connection) {
            $this->error("No Sense agent is answering on {$socketPath}.");

            return self::FAILURE;
        }

        fwrite($connection, (string) json_encode(['ping' => true]));
        fclose($connection);

        $this->info("The Sense agent on {$socketPath} is running.");

        return self::SUCCESS;
    }
}
