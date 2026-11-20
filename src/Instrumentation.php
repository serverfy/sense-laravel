<?php

namespace Serverfy\Sense;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Serverfy\Sense\Client\SenseClient;
use Serverfy\Sense\Tracing\TraceRecorder;
use Throwable;

/**
 * Wires framework events into the trace recorder: queries, cache, outgoing HTTP, queued jobs,
 * artisan commands and scheduled tasks. Every listener is wrapped so that a failure in
 * telemetry can never surface in the host application.
 */
class Instrumentation
{
    /** Commands that run for as long as the process lives, or that only orchestrate others. */
    public const IGNORED_COMMANDS = [
        'queue:work', 'queue:listen', 'queue:restart', 'horizon', 'horizon:work', 'horizon:supervisor',
        'schedule:run', 'schedule:work', 'schedule:finish', 'octane:start', 'octane:reload', 'reverb:start',
        'pulse:work', 'pulse:check', 'serve', 'tinker', 'sense:test', 'package:discover', 'vendor:publish',
        'list', 'help', 'serverfy:sense-agent',
    ];

    /** @var list<float> Start times of outgoing HTTP calls still in flight. */
    protected array $httpStarts = [];

    /** @var list<array{name: string, start: float}> Jobs running inside another trace (sync queue). */
    protected array $nestedJobs = [];

    /** Trace opened by this class for a command or scheduled task, so only its owner closes it. */
    protected ?string $ownedTraceId = null;

    public function __construct(
        protected SenseClient $client,
        protected TraceRecorder $recorder,
    ) {}

    public function register(Dispatcher $events): void
    {
        $listen = function (string $event, string $method) use ($events): void {
            $events->listen($event, function ($payload) use ($method): void {
                if (! $this->client->isConfigured() || $this->client->isSending()) {
                    return;
                }

                try {
                    $this->{$method}($payload);
                } catch (Throwable) {
                    // Telemetry never breaks the application.
                }
            });
        };

        $listen(QueryExecuted::class, 'query');

        if (config('sense.apm.cache', true)) {
            $listen(CacheHit::class, 'cache');
            $listen(CacheMissed::class, 'cache');
            $listen(KeyWritten::class, 'cache');
            $listen(KeyForgotten::class, 'cache');
        }

        if (config('sense.apm.http_client', true)) {
            $listen(RequestSending::class, 'httpSending');
            $listen(ResponseReceived::class, 'httpReceived');
            $listen(ConnectionFailed::class, 'httpFailed');
        }

        if (config('sense.apm.queue', true)) {
            $listen(JobQueued::class, 'jobQueued');
            $listen(JobProcessing::class, 'jobProcessing');
            $listen(JobProcessed::class, 'jobProcessed');
            $listen(JobFailed::class, 'jobFailed');
            $listen(JobExceptionOccurred::class, 'jobFailed');
            $listen(Looping::class, 'flush');
        }

        if (config('sense.apm.commands', true)) {
            $listen(CommandStarting::class, 'commandStarting');
            $listen(CommandFinished::class, 'commandFinished');
            $listen(ScheduledTaskStarting::class, 'taskStarting');
            $listen(ScheduledTaskFinished::class, 'taskFinished');
            $listen(ScheduledTaskFailed::class, 'taskFailed');
        }
    }

    protected function query(QueryExecuted $event): void
    {
        $sql = $this->client->scrubSql($event->sql);

        if ($this->recorder->isRecording()) {
            $this->recorder->span('db', mb_strcut($sql, 0, 120), (float) $event->time, ['connection' => $event->connectionName], $sql);

            return;
        }

        // Outside of a trace only slow queries are worth a network call.
        if ($event->time >= (float) config('sense.apm.slow_query_threshold', 100.0)) {
            $this->client->captureSpan('sql_query', mb_strcut($sql, 0, 120), (float) $event->time, [
                'sql' => $sql,
                'connection' => $event->connectionName,
            ]);
        }
    }

    protected function cache(object $event): void
    {
        $action = match (true) {
            $event instanceof CacheHit => 'hit',
            $event instanceof CacheMissed => 'miss',
            $event instanceof KeyWritten => 'write',
            default => 'forget',
        };

        $this->recorder->span('cache', $action.' '.mb_strcut((string) $event->key, 0, 120), 0.0, array_filter([
            'action' => $action,
            'store' => $event->storeName ?? null,
        ]));
    }

    protected function httpSending(RequestSending $event): void
    {
        $this->httpStarts[] = microtime(true);
    }

    protected function httpReceived(ResponseReceived $event): void
    {
        $started = array_pop($this->httpStarts);
        $seconds = $event->response->transferStats?->getTransferTime();
        $durationMs = $seconds !== null ? $seconds * 1000 : ($started ? (microtime(true) - $started) * 1000 : 0.0);
        $status = $event->response->status();

        $this->recorder->span('http_client', $this->httpName($event->request), $durationMs, ['status_code' => $status], null, $status >= 500);
    }

    protected function httpFailed(ConnectionFailed $event): void
    {
        $started = array_pop($this->httpStarts);

        $this->recorder->span('http_client', $this->httpName($event->request), $started ? (microtime(true) - $started) * 1000 : 0.0, ['error' => 'connection failed'], null, true);
    }

    /** Method, host and path only: query strings often carry credentials. */
    protected function httpName(object $request): string
    {
        $parts = parse_url((string) $request->url()) ?: [];

        return $request->method().' '.($parts['host'] ?? 'unknown').($parts['path'] ?? '/');
    }

    protected function jobQueued(JobQueued $event): void
    {
        $job = $event->job;
        $name = is_object($job) ? get_class($job) : (string) $job;

        $this->recorder->span('queue_dispatch', $name, 0.0, array_filter(['connection' => $event->connectionName]));
    }

    protected function jobProcessing(JobProcessing $event): void
    {
        $name = $event->job->resolveName();

        // A job on the sync queue runs inside the request that dispatched it: record it as a span.
        if ($this->recorder->isRecording()) {
            $this->nestedJobs[] = ['name' => $name, 'start' => microtime(true)];

            return;
        }

        $payload = $event->job->payload();

        $this->recorder->start('queue', $name, $payload['sense_trace_id'] ?? null, array_filter([
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'attempt' => $event->job->attempts(),
        ]));
    }

    protected function jobProcessed(JobProcessed $event): void
    {
        if ($nested = array_pop($this->nestedJobs)) {
            $this->recorder->span('queue_job', $nested['name'], (microtime(true) - $nested['start']) * 1000);

            return;
        }

        $this->recorder->finish();
        $this->client->flush();
    }

    protected function jobFailed(object $event): void
    {
        if ($nested = array_pop($this->nestedJobs)) {
            $this->recorder->span('queue_job', $nested['name'], (microtime(true) - $nested['start']) * 1000, [], null, true);

            return;
        }

        if ($this->recorder->isRecording()) {
            $this->recorder->markFailed();
            $this->recorder->finish();
        }

        $this->client->flush();
    }

    protected function commandStarting(CommandStarting $event): void
    {
        $command = (string) $event->command;

        if ($command === '' || $this->recorder->isRecording() || $this->commandIgnored($command)) {
            return;
        }

        $this->recorder->start('command', 'artisan '.$command);
        $this->ownedTraceId = $this->recorder->traceId();
    }

    protected function commandFinished(CommandFinished $event): void
    {
        if ($this->ownedTraceId === null || $this->recorder->traceId() !== $this->ownedTraceId) {
            return;
        }

        $this->ownedTraceId = null;

        if ($event->exitCode !== 0) {
            $this->recorder->markFailed();
        }

        $this->recorder->finish(data: ['exit_code' => $event->exitCode]);
        $this->client->flush();
    }

    protected function taskStarting(ScheduledTaskStarting $event): void
    {
        if ($this->recorder->isRecording()) {
            return;
        }

        $this->recorder->start('schedule', $this->taskName($event->task), null, array_filter([
            'expression' => $event->task->expression ?? null,
        ]));
        $this->ownedTraceId = $this->recorder->traceId();
    }

    protected function taskFinished(ScheduledTaskFinished $event): void
    {
        $this->closeTask(($event->task->exitCode ?? 0) !== 0);
    }

    protected function taskFailed(ScheduledTaskFailed $event): void
    {
        $this->closeTask(true);
    }

    protected function closeTask(bool $failed): void
    {
        if ($this->ownedTraceId === null || $this->recorder->traceId() !== $this->ownedTraceId) {
            return;
        }

        $this->ownedTraceId = null;

        if ($failed) {
            $this->recorder->markFailed();
        }

        $this->recorder->finish();
        $this->client->flush();
    }

    protected function taskName(object $task): string
    {
        $name = (string) ($task->description ?? '') ?: (string) ($task->command ?? '');

        // "'/usr/bin/php8.3' 'artisan' backup:run" → "backup:run"
        if (preg_match('/artisan[\'"]?\s+(.+)$/', $name, $matches)) {
            $name = $matches[1];
        }

        return mb_strcut(trim($name) ?: 'scheduled task', 0, 200);
    }

    protected function commandIgnored(string $command): bool
    {
        return in_array($command, array_merge(self::IGNORED_COMMANDS, (array) config('sense.apm.ignore_commands', [])), true);
    }

    protected function flush(object $event): void
    {
        if ($this->client->pending() > 0) {
            $this->client->flush();
        }
    }
}
