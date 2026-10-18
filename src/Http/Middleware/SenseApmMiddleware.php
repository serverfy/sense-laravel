<?php

namespace Serverfy\Sense\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Serverfy\Sense\Client\SenseClient;
use Symfony\Component\HttpFoundation\Response;

class SenseApmMiddleware
{
    protected SenseClient $client;

    public function __construct(?SenseClient $client = null)
    {
        $this->client = $client ?? app(SenseClient::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! defined('SERVERFY_REQUEST_START')) {
            define('SERVERFY_REQUEST_START', microtime(true));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        if (str_contains($request->path(), 'api/v1/telemetry')) {
            return;
        }

        $startTime = defined('SERVERFY_REQUEST_START') ? SERVERFY_REQUEST_START : (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        $route = $request->route();
        $routeName = $route ? ($route->getName() ?: $route->uri()) : $request->path();
        $spanName = $request->method().' /'.ltrim($routeName, '/');

        $this->client->captureSpan(
            type: 'http_request',
            name: $spanName,
            durationMs: $durationMs,
            meta: [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status_code' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 150),
            ]
        );
    }
}
