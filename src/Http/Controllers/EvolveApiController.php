<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Validation\ValidationException;
use Thinkneverland\Evolve\Core\Events\{
    EvolveModelCreated,
    EvolveModelUpdated,
    EvolveModelDeleted
};
use Thinkneverland\Evolve\Core\Support\{
    ModelRegistry,
    QueryPerformanceBridge,
    QueryMonitor,
    DatabaseConnectionPool,
    ErrorResponseFormatter
};

class EvolveApiController extends Controller
{
    protected $modelClass;
    protected $performanceBridge;
    protected $queryMonitor;

    public function __construct(Request $request)
    {
        // Skip initialization if we're just listing routes
        if ($this->isListingRoutes()) {
            return;
        }

        $modelAlias = $request->route('modelClass');

        if (empty($modelAlias)) {
            abort(400, "Model identifier is required.");
        }

        $this->modelClass = ModelRegistry::getModelClassByIdentifier($modelAlias);

        if (!$this->modelClass) {
            abort(404, "Model class not found for identifier '{$modelAlias}'.");
        }

        $this->performanceBridge = QueryPerformanceBridge::getInstance();
        $this->queryMonitor = QueryMonitor::getInstance();
    }

    /**
     * Check if we're currently listing routes via artisan command
     */
    protected function isListingRoutes(): bool
    {
        return app()->runningInConsole() &&
            (!isset($_SERVER['argv'][1]) || $_SERVER['argv'][1] === 'route:list');
    }

    public function index(Request $request)
    {
        $startTime = microtime(true);
        $connection = null;

        try {
            $connection = DatabaseConnectionPool::getConnection();

            $filters = $request->input('filter', []);
            $sorts = $request->input('sort', null);
            $perPage = $request->input('per_page', 15);

            $query = $this->modelClass::evolve($filters, $sorts);

            $paginator = $query->paginate($perPage);

            $paginator->getCollection()->each(function ($modelInstance) {
                $modelInstance->load($modelInstance->getAllRelations());
            });

            $paginator->setCollection(
                $paginator->getCollection()->map(function ($modelInstance) {
                    return $modelInstance->toDTO();
                })
            );

            $duration = microtime(true) - $startTime;
            $this->recordMetrics('index', null, $duration);

            DatabaseConnectionPool::releaseConnection($connection);

            return response()->json([
                'success' => true,
                'data' => $paginator,
                'message' => null,
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'index',
                'model' => $this->modelClass,
                'error' => $e->getMessage(),
            ]);

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to fetch resources. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    public function store(Request $request)
    {
        $startTime = microtime(true);
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = new $this->modelClass;

            try {
                $validated = $request->validate($modelInstance->getValidationRules('create'));
            } catch (ValidationException $e) {
                return response()->json(
                    ErrorResponseFormatter::formatValidationError($e),
                    ErrorResponseFormatter::getErrorStatusCode($e)
                );
            }

            $hookResponse = $this->runHook('beforeCreate', $request, $validated);
            if ($hookResponse) return $hookResponse;

            $modelInstance = $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                null,
                $request->boolean('avoid_duplicates', false)
            );

            $this->runHook('afterCreate', $request, $modelInstance);

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            $modelInstance->load($modelInstance->getAllRelations());

            Event::dispatch(new EvolveModelCreated($modelInstance));

            $duration = microtime(true) - $startTime;
            $this->recordMetrics('store', $modelInstance, $duration);

            return response()->json([
                'success' => true,
                'data' => $modelInstance->toDTO(),
                'message' => 'Resource created successfully.'
            ], 201);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'store',
                'model' => $this->modelClass,
                'error' => $e->getMessage()
            ]);

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to create resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    public function show(Request $request)
    {
        $startTime = microtime(true);
        $connection = null;

        try {
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($request->id);
            $modelInstance->load($modelInstance->getAllRelations());

            $duration = microtime(true) - $startTime;
            $this->recordMetrics('show', $modelInstance, $duration);

            DatabaseConnectionPool::releaseConnection($connection);

            return response()->json([
                'success' => true,
                'data' => $modelInstance->toDTO(),
                'message' => null
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'show',
                'model' => $this->modelClass,
                'id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to fetch resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    public function update(Request $request)
    {
        $startTime = microtime(true);
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($request->id);

            try {
                $validated = $request->validate(
                    $modelInstance->getValidationRules('update')
                );
            } catch (ValidationException $e) {
                return response()->json(
                    ErrorResponseFormatter::formatValidationError($e),
                    ErrorResponseFormatter::getErrorStatusCode($e)
                );
            }

            $hookResponse = $this->runHook('beforeUpdate', $request, $validated, $modelInstance);
            if ($hookResponse) return $hookResponse;

            $modelInstance = $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                $modelInstance
            );

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            $modelInstance->load($modelInstance->getAllRelations());

            $this->runHook('afterUpdate', $request, $modelInstance);

            Event::dispatch(new EvolveModelUpdated($modelInstance));

            $duration = microtime(true) - $startTime;
            $this->recordMetrics('update', $modelInstance, $duration);

            return response()->json([
                'success' => true,
                'data' => $modelInstance->toDTO(),
                'message' => 'Resource updated successfully.'
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'update',
                'model' => $this->modelClass,
                'id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to update resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    public function destroy(Request $request)
    {
        $startTime = microtime(true);
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($request->id);

            $hookResponse = $this->runHook('beforeDelete', request(), $modelInstance);
            if ($hookResponse) return $hookResponse;

            $modelInstance->delete();

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            $this->runHook('afterDelete', request(), $modelInstance);

            Event::dispatch(new EvolveModelDeleted($modelInstance));

            $duration = microtime(true) - $startTime;
            $this->recordMetrics('destroy', $modelInstance, $duration);

            return response()->json([
                'success' => true,
                'message' => 'Resource deleted successfully.'
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'destroy',
                'model' => $this->modelClass,
                'id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to delete resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    protected function saveModelWithRelations($modelClass, array $data, $existingModel = null, bool $avoidDuplicates = false)
    {
        $startTime = microtime(true);
        $modelInstance = new $modelClass;
        $relations = array_keys($modelInstance->getAllRelations());
        $attributes = array_diff_key($data, array_flip($relations));

        if ($avoidDuplicates) {
            $uniqueFields = $modelInstance->uniqueFields();
            $query = $modelClass::query();

            foreach ($uniqueFields as $field) {
                if (isset($attributes[$field])) {
                    $query->where($field, $attributes[$field]);
                }
            }

            $existingModel = $query->first();
        }

        if ($existingModel) {
            $existingModel->update($attributes);
            $modelInstance = $existingModel;
        } else {
            $modelInstance = $modelClass::create($attributes);
        }

        foreach ($relations as $relation) {
            if (isset($data[$relation])) {
                $this->processOptimizedRelation($modelInstance, $relation, $data[$relation], $avoidDuplicates);
            }
        }

        $duration = microtime(true) - $startTime;
        $this->recordQueryMetrics(
            $modelInstance->toSql(),
            $modelInstance->getBindings(),
            $duration
        );

        return $modelInstance;
    }

    protected function recordMetrics(string $action, $model, float $duration): void
    {
        try {
            DB::table('evolve_performance_metrics')->insert([
                'model_class' => $model ? get_class($model) : $this->modelClass,
                'action' => $action,
                'count' => 1,
                'avg_time' => $duration,
                'max_time' => $duration,
                'min_time' => $duration,
                'memory_usage' => memory_get_usage(true),
                'context' => json_encode([
                    'user_id' => auth()->id(),
                    'timestamp' => microtime(true),
                    'request_id' => request()->id(),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            report($e);
        }
    }

    protected function recordQueryMetrics(string $query, array $bindings, float $duration): void
    {
        try {
            $queryHash = md5($query . serialize($bindings));

            DB::table('evolve_query_metrics')->updateOrInsert(
                ['query_hash' => $queryHash],
                [
                    'query' => $query,
                    'execution_count' => DB::raw('execution_count + 1'),
                    'avg_duration' => DB::raw("((avg_duration * execution_count) + $duration) / (execution_count + 1)"),
                    'max_duration' => DB::raw("GREATEST(max_duration, $duration)"),
                    'min_duration' => DB::raw("LEAST(min_duration, $duration)"),
                    'bindings' => json_encode($bindings),
                    'context' => json_encode([
                        'user_id' => auth()->id(),
                        'timestamp' => microtime(true),
                        'request_id' => request()->id(),
                    ]),
                    'updated_at' => now(),
                ]
            );

            if ($duration >= config('evolve.monitoring.slow_query_threshold', 1.0)) {
                DB::table('evolve_slow_queries')->insert([
                    'query_hash' => $queryHash,
                    'query' => $query,
                    'duration' => $duration,
                    'bindings' => json_encode($bindings),
                    'context' => json_encode([
                        'user_id' => auth()->id(),
                        'timestamp' => microtime(true),
                        'request_id' => request()->id(),
                        'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
                    ]),
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            report($e);
        }
    }

    protected function processOptimizedRelation($modelInstance, string $relation, array $relationData, bool $avoidDuplicates): void
    {
        $relationMethod = $modelInstance->$relation();
        $relatedModelClass = get_class($relationMethod->getRelated());

        if ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            $this->processBelongsToManyRelation($relationMethod, $relationData, $avoidDuplicates);
        } else {
$this->processRegularRelation($relationMethod, $relationData, $avoidDuplicates, $relatedModelClass);
        }
    }

    protected function processBelongsToManyRelation($relationMethod, array $relationData, bool $avoidDuplicates): void
    {
        $relatedIds = [];
        $relatedModelClass = get_class($relationMethod->getRelated());

        foreach (array_chunk($relationData, 1000) as $chunk) {
            foreach ($chunk as $itemData) {
                $relatedModel = $this->saveModelWithRelations(
                    $relatedModelClass,
                    $itemData,
                    null,
                    $avoidDuplicates
                );
                $relatedIds[] = $relatedModel->id;
            }
        }

        $relationMethod->sync($relatedIds);
    }

    protected function processRegularRelation($relationMethod, array $relationData, bool $avoidDuplicates, string $relatedModelClass): void
    {
        foreach (array_chunk($relationData, 1000) as $chunk) {
            $relatedModels = [];

            foreach ($chunk as $itemData) {
                $existingRelatedModel = null;

                if ($avoidDuplicates) {
                    $uniqueFields = $relatedModelClass::uniqueFields();
                    $query = $relatedModelClass::query();

                    foreach ($uniqueFields as $field) {
                        if (isset($itemData[$field])) {
                            $query->where($field, $itemData[$field]);
                        }
                    }

                    $existingRelatedModel = $query->first();
                }

                $relatedModel = $this->saveModelWithRelations(
                    $relatedModelClass,
                    $itemData,
                    $existingRelatedModel,
                    $avoidDuplicates
                );

                $relatedModels[] = $relatedModel;
            }

            $relationMethod->saveMany($relatedModels);
        }
    }

    protected function runHook(string $hookName, Request $request, &$data, $modelInstance = null)
    {
        $hookClass = $this->getHookClass();

        if ($hookClass && method_exists($hookClass, $hookName)) {
            try {
                $startTime = microtime(true);
                $result = $hookClass->{$hookName}($request, $data, $modelInstance);

                $this->queryMonitor->recordQuery("hook_{$hookName}", [
                    'duration' => microtime(true) - $startTime,
                    'model' => $this->modelClass,
                    'hook' => $hookName,
                    'timestamp' => time()
                ]);

                return $result;
            } catch (\Exception $e) {
                $this->queryMonitor->recordError([
                    'operation' => "hook_{$hookName}",
                    'model' => $this->modelClass,
                    'error' => $e->getMessage()
                ]);

                return response()->json(
                    ErrorResponseFormatter::formatError(
                        $e,
                        'An error occurred while processing your request.'
                    ),
                    ErrorResponseFormatter::getErrorStatusCode($e)
                );
            }
        }

        return null;
    }

    protected function getHookClass()
    {
        $hookClassName = 'App\\Http\\Controllers\\Api\\' . class_basename($this->modelClass) . 'Controller';

        if (class_exists($hookClassName)) {
            return new $hookClassName;
        }

        return null;
    }
}
