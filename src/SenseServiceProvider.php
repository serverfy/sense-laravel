<?php

namespace Serverfy\Sense;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Serverfy\Sense\Client\SenseClient;
use Serverfy\Sense\Console\Commands\SenseTestCommand;
use Serverfy\Sense\Http\Middleware\SenseApmMiddleware;
use Serverfy\Sense\Listeners\SenseQueryListener;

class SenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sense.php', 'sense');

        $this->app->singleton(SenseClient::class, function () {
            return new SenseClient;
        });

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

        // Register Database Query Listener if APM is enabled
        if (config('sense.apm.enabled', true)) {
            Event::listen(QueryExecuted::class, SenseQueryListener::class);

            // Register Middleware in HTTP Kernel if available
            if ($this->app->bound(HttpKernel::class)) {
                /** @var Kernel $kernel */
                $kernel = $this->app->make(HttpKernel::class);
                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(SenseApmMiddleware::class);
                }
            }
        }
    }
}
