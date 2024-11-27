<?php

namespace Thinkneverland\Evolve\Api;

use Illuminate\Support\ServiceProvider;
use Thinkneverland\Evolve\Api\Console\Commands\GenerateDocsCommand;

class EvolveApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/evolve-api.php',
            'evolve-api'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDocsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/evolve-api.php' => config_path('evolve-api.php'),
                __DIR__ . '/../resources/views' => resource_path('views/vendor/evolve-api'),
            ], 'evolve-api');
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'evolve-api');
    }
}
