<?php

declare(strict_types=1);

namespace PayZephyr\Trace;

use Illuminate\Support\ServiceProvider;
use PayZephyr\Trace\Commands\PruneTraceEventsCommand;
use PayZephyr\Trace\Commands\TracePaymentCommand;
use PayZephyr\Trace\Contracts\TraceRecorderInterface;
use PayZephyr\Trace\Services\PayloadRedactor;
use PayZephyr\Trace\Services\TraceRecorder;
use PayZephyr\Trace\Services\TraceTimelineBuilder;

class TraceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/trace.php',
            'trace'
        );

        // Register core services
        $this->app->singleton(PayloadRedactor::class);

        // Bind interface to implementation (Open-Closed Principle)
        $this->app->singleton(TraceRecorderInterface::class, TraceRecorder::class);
        $this->app->singleton(TraceRecorder::class, function ($app) {
            return new TraceRecorder($app->make(PayloadRedactor::class));
        });

        $this->app->singleton(TraceTimelineBuilder::class);

        // Register facade accessor - points to interface
        $this->app->singleton('payzephyr.trace', function ($app) {
            return $app->make(TraceRecorderInterface::class);
        });

        // Register timeline builder alias for convenience
        $this->app->alias(TraceTimelineBuilder::class, 'payzephyr.timeline');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/trace.php' => config_path('trace.php'),
        ], 'payzephyr-trace-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'payzephyr-trace-migrations');

        // Load migrations automatically in development
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                TracePaymentCommand::class,
                PruneTraceEventsCommand::class,
            ]);
        }
    }
}
