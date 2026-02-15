<?php

declare(strict_types=1);

namespace Laragentic;

use Illuminate\Support\ServiceProvider;

class LaragenticServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/agentic.php',
            'agentic',
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/agentic.php' => config_path('agentic.php'),
            ], 'agentic-config');
        }
    }
}
