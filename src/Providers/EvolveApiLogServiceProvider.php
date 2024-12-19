<?php

namespace Thinkneverland\Evolve\Api\Providers;

use Illuminate\Support\ServiceProvider;
use Thinkneverland\Evolve\Core\Facades\EvolveLog;

class EvolveApiLogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // No registration needed as we're using EvolveCore's LogManager
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Configure API-specific logging context
        $this->configureLogging();
    }

    /**
     * Configure API-specific logging
     */
    protected function configureLogging(): void
    {
        // Add API-specific logging configuration if needed
        $config = $this->app['config'];
        
        // Only configure if logging is enabled
        if (!$config->get('evolve.logging.enabled', true)) {
            return;
        }

        // Add API-specific logging channel if separate files are enabled
        if ($config->get('evolve.logging.separate_files', false)) {
            $channel = $config->get('evolve.logging.channel', 'evolve') . '_api';
            $path = str_replace('.log', '_api.log', $config->get('evolve.logging.path', storage_path('logs/evolve.log')));
            
            $config->set('logging.channels.' . $channel, [
                'driver' => 'daily',
                'path' => $path,
                'level' => $config->get('evolve.logging.level', 'debug'),
                'days' => $config->get('evolve.logging.max_files', 30),
                'bubble' => $config->get('evolve.logging.bubble', true),
                'permission' => $config->get('evolve.logging.permission'),
            ]);
        }
    }
}
