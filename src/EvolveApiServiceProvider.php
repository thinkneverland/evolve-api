<?php

namespace Thinkneverland\Evolve\Api\Providers;

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
            __DIR__ . '/../../config/evolve-api.php',
            'evolve-api'
        );

        // Register the GenerateDocsCommand if running in the console
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDocsCommand::class,
            ]);

            // Publish configuration file
            $this->publishes([
                __DIR__ . '/../../config/evolve-api.php' => config_path('evolve-api.php'),
            ], 'evolve-api-config');

            // Publish views for customization
            $this->publishes([
                __DIR__ . '/../../resources/views' => resource_path('views/vendor/evolve-api'),
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
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'evolve-api');

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

        $this->loadRoutesFrom(function () use ($routePrefix) {
            Route::group([
                'prefix' => $routePrefix,
                'middleware' => ['web'], // Adjust middleware as needed
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
            });
        });
    }

    /**
     * Clone Swagger UI repository and copy necessary assets.
     *
     * @return void
     */
    protected function cloneAndCopySwaggerUiAssets()
    {
        $targetDir = public_path('vendor/evolve-api/swagger-ui');
        $files = [
            'swagger-ui.css',
            'swagger-ui-bundle.js',
            'swagger-ui-standalone-preset.js',
        ];

        // Check if all required files already exist to prevent duplicate operations
        $allFilesExist = true;
        foreach ($files as $file) {
            if (!file_exists("{$targetDir}/{$file}")) {
                $allFilesExist = false;
                break;
            }
        }

        if ($allFilesExist) {
            // All files are already present; no action needed
            $this->info('Swagger UI assets already exist. Skipping cloning and copying.');
            return;
        }

        // Define temporary directory for cloning
        $tempDir = storage_path('app/temp/swagger-ui');

        // Define Swagger UI repository URL
        $repoUrl = 'https://github.com/swagger-api/swagger-ui.git';

        // Remove existing temporary directory if it exists
        if (is_dir($tempDir)) {
            $this->deleteDirectory($tempDir);
            $this->info("Deleted existing temporary directory: {$tempDir}");
        }

        // Clone the Swagger UI repository into the temporary directory
        $this->info('Cloning Swagger UI repository...');
        $cloneCommand = "git clone --depth=1 {$repoUrl} {$tempDir}";
        exec($cloneCommand, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->error('Failed to clone Swagger UI repository. Please ensure Git is installed and accessible.');
            return;
        }

        // Define the source directory within the cloned repository
        $sourceDir = "{$tempDir}/dist";

        // Ensure the source directory exists
        if (!is_dir($sourceDir)) {
            $this->error("Source directory {$sourceDir} does not exist in the cloned repository.");
            $this->deleteDirectory($tempDir);
            return;
        }

        // Create the target directory if it doesn't exist
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
            $this->info("Created target directory: {$targetDir}");
        }

        // Copy the required Swagger UI files from the source to the target directory
        foreach ($files as $file) {
            $sourceFile = "{$sourceDir}/{$file}";
            $destinationFile = "{$targetDir}/{$file}";

            if (file_exists($sourceFile)) {
                copy($sourceFile, $destinationFile);
                $this->info("Copied {$file} to {$targetDir}");
            } else {
                $this->error("File {$file} does not exist in the cloned repository's dist directory.");
            }
        }

        // Remove the temporary directory after copying
        $this->deleteDirectory($tempDir);
        $this->info('Removed temporary Swagger UI repository clone.');
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
}
