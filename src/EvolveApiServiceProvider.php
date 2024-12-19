<?php

namespace Thinkneverland\Evolve\Api;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Thinkneverland\Evolve\Core\Facades\EvolveLog;
use Thinkneverland\Evolve\Api\Console\Commands\GenerateDocsCommand;
use Thinkneverland\Evolve\Api\Http\Controllers\DocsController;
use Thinkneverland\Evolve\Api\Http\Controllers\EvolveApiController;

class EvolveApiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/evolve-api.php', 'evolve-api'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/evolve-api.php' => config_path('evolve-api.php'),
        ], 'evolve-api-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'evolve-api');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDocsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/evolve-api'),
            ], 'evolve-api');
        }

        // Register routes after the application has booted
        $this->app->booted(function () {
            $this->registerRoutes();
        });
    }

    /**
     * Register routes after all models are loaded
     */
    protected function registerRoutes()
    {
        try {
            // Check if routes are cached
            if ($this->app->routesAreCached()) {
                return; // Routes are already cached by Laravel
            }

            $config = config('evolve-api', []);
            $prefix = $config['route_prefix'] ?? 'evolve-api';
            
            // Load documentation routes
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

            // Register dynamic model routes
            $router = $this->app['router'];
            $modelRegistry = app(\Thinkneverland\Evolve\Core\Support\ModelRegistry::class);

            Route::group([
                'prefix' => $prefix,
                'middleware' => ['api']
            ], function () use ($modelRegistry) {
                foreach ($modelRegistry->getRegistry() as $modelClass => $config) {
                    $identifier = $config['identifier'];
                    
                    Route::get($identifier, [EvolveApiController::class, 'index'])
                        ->defaults('modelClass', $identifier);
                    Route::post($identifier, [EvolveApiController::class, 'store'])
                        ->defaults('modelClass', $identifier);
                    Route::get("$identifier/{id}", [EvolveApiController::class, 'show'])
                        ->defaults('modelClass', $identifier);
                    Route::put("$identifier/{id}", [EvolveApiController::class, 'update'])
                        ->defaults('modelClass', $identifier);
                    Route::delete("$identifier/{id}", [EvolveApiController::class, 'destroy'])
                        ->defaults('modelClass', $identifier);
                }
            });

            // Log registered routes if enabled
            if (config('evolve-api.logging.routes', false)) {
                $routes = collect($router->getRoutes())
                    ->filter(function ($route) use ($prefix) {
                        return str_starts_with($route->uri(), $prefix);
                    })
                    ->map(function ($route) {
                        return [
                            'method' => implode('|', $route->methods()),
                            'uri' => $route->uri(),
                            'name' => $route->getName(),
                            'action' => $route->getActionName()
                        ];
                    })->values()->all();

                EvolveLog::info('EvolveAPI: Routes registered successfully', [
                    'prefix' => $prefix,
                    'routes' => $routes
                ]);
            }
        } catch (\Throwable $e) {
            EvolveLog::error('EvolveAPI: Failed to register routes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
