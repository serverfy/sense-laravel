<?php

namespace Serverfy\Sense;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Serverfy\Sense\Client\SenseClient;
use Serverfy\Sense\Console\Commands\SenseTestCommand;
use Serverfy\Sense\Http\Middleware\SenseApmMiddleware;
use Serverfy\Sense\Tracing\TraceRecorder;
use Throwable;

class SenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sense.php', 'sense');

        $this->app->singleton(SenseClient::class, fn () => new SenseClient(
            sampleRate: (float) config('sense.sample_rate', 1.0),
        ));

        $this->app->singleton(TraceRecorder::class, fn ($app) => new TraceRecorder(
            $app->make(SenseClient::class),
            (int) config('sense.apm.max_spans', 300),
        ));

        $this->app->singleton(Instrumentation::class);

        $this->app->alias(SenseClient::class, 'sense');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sense.php' => config_path('sense.php'),
            ], 'sense-config');

            $this->commands([
                SenseTestCommand::class,
            ]);
        }

        if (! config('sense.enabled', true) || empty(config('sense.dsn'))) {
            return;
        }

        if (config('sense.errors.enabled', true)) {
            $this->reportExceptions();
        }

        if (config('sense.apm.enabled', true)) {
            $this->app->make(Instrumentation::class)->register($this->app['events']);
            $this->traceRequests();
            $this->linkQueuedJobsToTheirOrigin();
        }

        // Whatever is still buffered when the process winds down goes out then.
        $this->app->terminating(function (): void {
            try {
                $this->app->make(SenseClient::class)->flush();
            } catch (Throwable) {
                // Telemetry never breaks the application.
            }
        });
    }

    /**
     * Every exception the application reports is sent too, with the request and the user it
     * happened to. Exceptions the application chose not to report are left alone.
     */
    protected function reportExceptions(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e): void {
            try {
                $this->app->make(TraceRecorder::class)->markFailed();
                $this->app->make(SenseClient::class)->captureException($e, $this->exceptionContext());
            } catch (Throwable) {
                // Telemetry never breaks the application.
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function exceptionContext(): array
    {
        $breadcrumbs = $this->app->make(TraceRecorder::class)->breadcrumbs();
        $context = $breadcrumbs ? ['breadcrumbs' => $breadcrumbs] : [];

        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return $context;
        }

        $request = $this->app->make('request');
        $client = $this->app->make(SenseClient::class);
        $route = $request->route();

        $context += [
            'request' => [
                'url' => $request->url(),
                'method' => $request->method(),
                'route' => is_object($route) && method_exists($route, 'uri') ? $route->uri() : null,
                'ip' => $request->ip(),
                'headers' => $client->scrubPii(array_map(
                    fn ($values) => mb_strcut(implode(', ', (array) $values), 0, 500),
                    $request->headers->all()
                )),
            ],
        ];

        if (config('sense.errors.send_request_body', false)) {
            $context['request']['payload'] = $client->scrubPii($request->except((array) config('sense.errors.hidden_fields', [])));
        }

        try {
            if ($user = $request->user()) {
                $context['user'] = ['id' => (string) $user->getAuthIdentifier()];
            }
        } catch (Throwable) {
            // No user is fine.
        }

        return $context;
    }

    protected function traceRequests(): void
    {
        if (! $this->app->bound(HttpKernel::class)) {
            return;
        }

        $kernel = $this->app->make(HttpKernel::class);

        // First in the stack, so the trace covers the other middleware as well.
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(SenseApmMiddleware::class);
        }
    }

    /**
     * A job dispatched during a request carries that request's trace id, so the job's own
     * trace can point back to where it came from.
     */
    protected function linkQueuedJobsToTheirOrigin(): void
    {
        if (! config('sense.apm.queue', true)) {
            return;
        }

        Queue::createPayloadUsing(function (): array {
            $traceId = SenseClient::getTraceContext()['trace_id'] ?? null;

            return $traceId ? ['sense_trace_id' => $traceId] : [];
        });
    }
}
