<?php

namespace Thinkneverland\Evolve\Api\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;
use Thinkneverland\Evolve\Core\Traits\Evolvable;

class GenerateDocsCommand extends Command
{
    protected $signature = 'evolve-api:generate-docs';
    protected $description = 'Generate OpenAPI documentation for Evolvable models';

    public function handle()
    {
        $this->info('Generating API documentation...');

        $docs = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('app.name') . ' API Documentation',
                'version' => '1.0.0',
            ],
            'paths' => $this->generatePaths(),
            'components' => [
                'schemas' => $this->generateSchemas(),
            ],
        ];

        $path = storage_path('app/evolve-api-docs.json');
        file_put_contents($path, json_encode($docs, JSON_PRETTY_PRINT));

        $this->info('Documentation generated successfully!');
    }

    protected function generatePaths(): array
    {
        $paths = [];
        $models = $this->getEvolvableModels();

        foreach ($models as $modelClass) {
            $name = Str::plural(Str::kebab(class_basename($modelClass)));

            // Add standard CRUD endpoints
            $paths["/{$name}"] = $this->generateListAndCreatePaths($modelClass);
            $paths["/{$name}/{id}"] = $this->generateSingleResourcePaths($modelClass);
        }

        return $paths;
    }

    protected function generateSchemas(): array
    {
        $schemas = [];
        $models = $this->getEvolvableModels();

        foreach ($models as $modelClass) {
            $model = new $modelClass();
            $schemas[class_basename($modelClass)] = [
                'type' => 'object',
                'properties' => $this->getModelProperties($model),
            ];
        }

        return $schemas;
    }

    protected function getEvolvableModels(): array
    {
        $models = [];
        $modelPath = app_path('Models');

        foreach (\File::allFiles($modelPath) as $file) {
            $class = app()->getNamespace() . 'Models\\' .
                str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);
                $traits = class_uses_recursive($class);

                if (isset($traits[Evolvable::class])) {
                    $models[] = $class;
                }
            }
        }

        return $models;
    }

    // Additional helper methods would go here...
}
