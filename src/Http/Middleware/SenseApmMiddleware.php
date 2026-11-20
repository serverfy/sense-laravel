<?php

namespace Serverfy\Sense\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Serverfy\Sense\Client\SenseClient;
use Serverfy\Sense\Tracing\TraceRecorder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens a trace for every HTTP request and sends it, with everything collected inside it,
 * after the response has gone out.
 */
class SenseApmMiddleware
{
    protected SenseClient $client;

    protected TraceRecorder $recorder;

    public function __construct(?SenseClient $client = null, ?TraceRecorder $recorder = null)
    {
        $this->client = $client ?? app(SenseClient::class);
        $this->recorder = $recorder ?? ($client ? new TraceRecorder($client) : app(TraceRecorder::class));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->client->isConfigured() && ! $this->ignored($request)) {
            $this->recorder->start('http', $request->method().' /'.ltrim($request->path(), '/'));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        try {
            if ($this->recorder->isRecording()) {
                $this->recorder->rename($this->name($request));
                $this->recorder->finish(
                    statusCode: $response->getStatusCode(),
                    userIdentifier: $this->userIdentifier($request),
                    data: [
                        'method' => $request->method(),
                        'path' => '/'.ltrim($request->path(), '/'),
                        'host' => $request->getHost(),
                        'ip' => $request->ip(),
                        'user_agent' => mb_strcut((string) $request->userAgent(), 0, 150),
                    ],
                );
            }

            $this->client->flush();
        } catch (Throwable) {
            // Telemetry never breaks the request.
        }
    }

    /**
     * The route pattern, not the concrete URL, so "/orders/1" and "/orders/2" count as one route.
     */
    protected function name(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return $request->method().' /'.ltrim((string) $route->uri(), '/');
        }

        return $request->method().' (unmatched route)';
    }

    protected function userIdentifier(Request $request): ?string
    {
        try {
            $id = $request->user()?->getAuthIdentifier();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }

    protected function ignored(Request $request): bool
    {
        $path = $request->path();

        if (str_contains($path, 'api/v1/telemetry')) {
            return true;
        }

        foreach ((array) config('sense.apm.ignore_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
