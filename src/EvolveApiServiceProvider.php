<?php

namespace Thinkneverland\Evolve\Api;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Thinkneverland\Evolve\Api\Console\Commands\GenerateDocsCommand;

class EvolveApiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/evolve-api.php',
            'evolve-api'
        );

        // Register the GenerateDocsCommand if running in the console
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDocsCommand::class,
            ]);

            // Publish configuration file
            $this->publishes([
                __DIR__ . '/../config/evolve-api.php' => config_path('evolve-api.php'),
            ], 'evolve-api-config');

            // Publish views for customization
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/evolve-api'),
            ], 'evolve-api-views');
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Load the package routes with configurable prefix
        $this->loadRoutesWithPrefix();

        // Load the package views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'evolve-api');

        // Clone and copy Swagger UI assets
        $this->cloneAndCopySwaggerUiAssets();
    }

    /**
     * Load package routes with a configurable prefix.
     *
     * @return void
     */
    protected function loadRoutesWithPrefix()
    {
        $routePrefix = config('evolve-api.route_prefix', 'evolve-api');

        Route::group([
            'prefix' => $routePrefix,
            'middleware' => ['web'], // Adjust middleware as needed
        ], function () {
            require __DIR__ . '/../routes/web.php';
        });
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $dir
     * @return void
     */
    protected function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return;
        }

        if (!is_dir($dir)) {
            unlink($dir);
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $path = "{$dir}/{$item}";
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
    protected function cloneAndCopySwaggerUiAssets()
    {
        $targetDir = public_path('vendor/evolve-api/swagger-ui');
        $files = [
            'swagger-ui.css',
            'swagger-ui-bundle.js',
            'swagger-ui-standalone-preset.js',
        ];

        // Check if all required files already exist
        $allFilesExist = true;
        foreach ($files as $file) {
            if (!file_exists("{$targetDir}/{$file}")) {
                $allFilesExist = false;
                break;
            }
        }

        if ($allFilesExist) {
            Log::info('Swagger UI assets already exist. Skipping cloning and copying.');
            return;
        }

        $tempDir = storage_path('app/temp/swagger-ui');
        $repoUrl = 'https://github.com/swagger-api/swagger-ui.git';

        if (is_dir($tempDir)) {
            $this->deleteDirectory($tempDir);
            Log::info("Deleted existing temporary directory: {$tempDir}");
        }

        Log::info('Cloning Swagger UI repository...');
        $cloneCommand = "git clone --depth=1 {$repoUrl} {$tempDir}";
        exec($cloneCommand, $output, $returnVar);

        if ($returnVar !== 0) {
            Log::error('Failed to clone Swagger UI repository. Please ensure Git is installed and accessible.');
            return;
        }

        $sourceDir = "{$tempDir}/dist";

        if (!is_dir($sourceDir)) {
            Log::error("Source directory {$sourceDir} does not exist in the cloned repository.");
            $this->deleteDirectory($tempDir);
            return;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
            Log::info("Created target directory: {$targetDir}");
        }

        foreach ($files as $file) {
            $sourceFile = "{$sourceDir}/{$file}";
            $destinationFile = "{$targetDir}/{$file}";

            if (file_exists($sourceFile)) {
                copy($sourceFile, $destinationFile);
                Log::info("Copied {$file} to {$targetDir}");
            } else {
                Log::error("File {$file} does not exist in the cloned repository's dist directory.");
            }
        }

        $this->deleteDirectory($tempDir);
        Log::info('Removed temporary Swagger UI repository clone.');
    }

}
