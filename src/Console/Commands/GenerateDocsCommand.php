<?php

namespace Thinkneverland\Evolve\Api\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Illuminate\Support\Facades\Schema;

class GenerateDocsCommand extends Command
{
    protected $signature = 'evolve-api:generate-docs';
    protected $description = 'Generate Swagger documentation for Evolvable models';

    public function handle()
    {
        $this->info('Generating Swagger documentation...');

        // Output path for the Swagger JSON file
        $outputPath = public_path('evolve-api-docs.json');

        // Collect all Evolvable models
        $models = $this->getEvolvableModels();

        // Generate Swagger documentation
        $swagger = $this->generateSwaggerDocumentation($models);

        // Save the Swagger JSON file
        File::put($outputPath, json_encode($swagger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Swagger documentation generated successfully at ' . $outputPath);
    }

    protected function getEvolvableModels()
    {
        // Scan the app's Models directory for models using the Evolvable trait
        $models = [];
        $modelPath = app_path('Models');

        if (!is_dir($modelPath)) {
            $this->error('Models directory not found at ' . $modelPath);
            return $models;
        }

        $files = File::allFiles($modelPath);

        foreach ($files as $file) {
            $namespace = $this->getClassNamespace($file->getPathname());
            $className = $namespace . '\\' . $file->getFilenameWithoutExtension();

            if (class_exists($className)) {
                $reflection = new ReflectionClass($className);

                if ($reflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                    // Check if the model uses the Evolvable trait
                    $traits = $this->getClassTraits($reflection);

                    if (in_array('Thinkneverland\Evolve\Core\Traits\SortableFilterableTrait', $traits)) {
                        $models[] = $className;
                    }
                }
            }
        }

        return $models;
    }

    protected function generateSwaggerDocumentation($models)
    {
        // Initialize the Swagger documentation array
        $swagger = [
            'swagger' => '2.0',
            'info' => [
                'title' => config('app.name') . ' API Documentation',
                'version' => '1.0.0',
            ],
            'host' => parse_url(config('app.url'), PHP_URL_HOST),
            'basePath' => '/',
            'schemes' => ['http', 'https'],
            'paths' => [],
            'definitions' => [],
        ];

        foreach ($models as $modelClass) {
            $modelInstance = new $modelClass;

            $modelName = class_basename($modelClass);
            $modelSlug = Str::kebab(Str::plural($modelName));
            $modelEndpoint = '/' . $modelSlug;

            // Generate paths for index, store, show, update, delete
            $swagger['paths'] = array_merge($swagger['paths'], $this->generateModelPaths($modelClass, $modelEndpoint));

            // Generate definitions for the model
            $swagger['definitions'][$modelName] = $this->generateModelDefinition($modelClass);
        }

        return $swagger;
    }

    protected function generateModelPaths($modelClass, $modelEndpoint)
    {
        $modelName = class_basename($modelClass);

        $paths = [];

        // Index and Store
        $paths[$modelEndpoint] = [
            'get' => [
                'tags' => [$modelName],
                'summary' => 'Get a list of ' . Str::plural($modelName),
                'parameters' => [
                    [
                        'name' => 'filter',
                        'in' => 'query',
                        'required' => false,
                        'type' => 'string',
                        'description' => 'Filter parameters',
                    ],
                    [
                        'name' => 'sort',
                        'in' => 'query',
                        'required' => false,
                        'type' => 'string',
                        'description' => 'Sort parameters',
                    ],
                    [
                        'name' => 'per_page',
                        'in' => 'query',
                        'required' => false,
                        'type' => 'integer',
                        'description' => 'Number of results per page',
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/definitions/' . $modelName,
                            ],
                        ],
                    ],
                ],
            ],
            'post' => [
                'tags' => [$modelName],
                'summary' => 'Create a new ' . $modelName,
                'parameters' => [
                    [
                        'name' => 'body',
                        'in' => 'body',
                        'required' => true,
                        'schema' => [
                            '$ref' => '#/definitions/' . $modelName,
                        ],
                    ],
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Resource created',
                        'schema' => [
                            '$ref' => '#/definitions/' . $modelName,
                        ],
                    ],
                ],
            ],
        ];

        // Show, Update, Delete
        $paths[$modelEndpoint . '/{id}'] = [
            'get' => [
                'tags' => [$modelName],
                'summary' => 'Get a specific ' . $modelName,
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'ID of the ' . $modelName,
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'schema' => [
                            '$ref' => '#/definitions/' . $modelName,
                        ],
                    ],
                ],
            ],
            'put' => [
                'tags' => [$modelName],
                'summary' => 'Update a specific ' . $modelName,
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'ID of the ' . $modelName,
                    ],
                    [
                        'name' => 'body',
                        'in' => 'body',
                        'required' => true,
                        'schema' => [
                            '$ref' => '#/definitions/' . $modelName,
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Resource updated',
                        'schema' => [
                            '$ref' => '#/definitions/' . $modelName,
                        ],
                    ],
                ],
            ],
            'delete' => [
                'tags' => [$modelName],
                'summary' => 'Delete a specific ' . $modelName,
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'ID of the ' . $modelName,
                    ],
                ],
                'responses' => [
                    '204' => [
                        'description' => 'Resource deleted',
                    ],
                ],
            ],
        ];

        return $paths;
    }

    protected function generateModelDefinition($modelClass)
    {
        $modelInstance = new $modelClass;
        $table = $modelInstance->getTable();

        // Get the columns of the table using Schema facade directly
        try {
            $columns = Schema::getColumnListing($table);
            $properties = [];

            foreach ($columns as $column) {
                try {
                    // Get column type
                    $type = Schema::getColumnType($table, $column);
                    $swaggerType = $this->mapColumnTypeToSwaggerType($type);

                    $properties[$column] = [
                        'type' => $swaggerType,
                    ];
                } catch (\Exception $e) {
                    Log::warning("Could not determine type for column {$column} in table {$table}");
                    $properties[$column] = [
                        'type' => 'string'
                    ];
                }
            }

            // Handle relationships
            $reflection = new ReflectionClass($modelClass);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->class != $modelClass || $method->getNumberOfParameters() > 0) {
                    continue;
                }

                try {
                    $return = $method->invoke($modelInstance);

                    if ($return instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $method->getName();
                        $relatedModelClass = get_class($return->getRelated());
                        $relatedModelName = class_basename($relatedModelClass);

                        $properties[$relationName] = [
                            'type' => 'object',
                            '$ref' => '#/definitions/' . $relatedModelName,
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip methods that cannot be invoked
                    continue;
                }
            }

            return [
                'type' => 'object',
                'properties' => $properties,
            ];

        } catch (\Exception $e) {
            Log::error("Failed to generate model definition for {$modelClass}: " . $e->getMessage());
            return [
                'type' => 'object',
                'properties' => []
            ];
        }
    }

    protected function mapColumnTypeToSwaggerType($type)
    {
        $mapping = [
            'string' => 'string',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'date' => 'string',
            'datetime' => 'string',
            'float' => 'number',
            'text' => 'string',
            // Add other mappings as needed
        ];

        return $mapping[$type] ?? 'string';
    }

    protected function getClassNamespace($filePath)
    {
        $src = file_get_contents($filePath);

        if (preg_match('#^namespace\s+(.+?);$#sm', $src, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function getClassTraits(ReflectionClass $class)
    {
        $traits = [];

        do {
            $traits = array_merge($traits, $class->getTraitNames());
        } while ($class = $class->getParentClass());

        return $traits;
    }
}
