<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Thinkneverland\Evolve\Core\Events\{EvolveModelCreated, EvolveModelDeleted, EvolveModelUpdated};
use Thinkneverland\Evolve\Core\Support\{DatabaseConnectionPool, QueryMonitor, QueryOptimizer, QueryPerformanceBridge};

class EvolveApiController extends Controller
{
    protected $modelClass;
    protected $performanceBridge;
    protected $queryMonitor;
    protected $queryOptimizer;

    public function __construct(Request $request)
    {
        $this->modelClass = $request->route('modelClass');
        $this->performanceBridge = QueryPerformanceBridge::getInstance();
        $this->queryMonitor = QueryMonitor::getInstance();
        $this->queryOptimizer = new QueryOptimizer();
    }

    public function index(Request $request)
    {
        $filters = $request->input('filter', []);
        $sorts = $request->input('sort', null);
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);

        try {
            $startTime = microtime(true);
            $connection = DatabaseConnectionPool::getConnection();

            $query = $this->modelClass::query();
            $this->queryOptimizer->optimizeQuery($query, [
                'context' => 'index',
                'filters' => $filters,
                'sorts' => $sorts
            ]);

            $query = $this->modelClass::evolve($filters, $sorts);
            $total = $query->count();
            $query->forPage($page, $perPage);

            $result = $query->get();
            $duration = microtime(true) - $startTime;

            $this->queryMonitor->recordQuery(
                md5($query->toSql()),
                [
                    'duration' => $duration,
                    'query' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                    'count' => $result->count(),
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => microtime(true)
                ]
            );

            $data = $result->unique('id')->values();
            DatabaseConnectionPool::releaseConnection($connection);

            return $this->formatSuccessResponse('list', [
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                ]
            ]);
        } catch (\Exception $e) {
            if (isset($connection)) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'index',
                'model' => $this->modelClass,
                'error' => $e->getMessage(),
            ]);

            return $this->formatErrorResponse($e);
        }
    }

    public function store(Request $request)
    {
        $avoidDuplicates = $request->boolean('avoid_duplicates', false);
        $connection = null;
        $startTime = microtime(true);

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = new $this->modelClass();

            try {
                $validated = $request->validate($modelInstance->getValidationRules('create'));
            } catch (ValidationException $e) {
                return $this->handleValidationException($e);
            }

            $hookResponse = $this->runHook('beforeCreate', $request, $validated);
            if ($hookResponse) {
                return $hookResponse;
            }

            $modelInstance = $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                null,
                $avoidDuplicates
            );

            $duration = microtime(true) - $startTime;
            $this->queryMonitor->recordQuery(
                'store_' . get_class($modelInstance),
                [
                    'duration' => $duration,
                    'operation' => 'store',
                    'model' => get_class($modelInstance),
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => microtime(true)
                ]
            );

            $this->runHook('afterCreate', $request, $modelInstance);
            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            Event::dispatch(new EvolveModelCreated($modelInstance));

            return $this->formatSuccessResponse('created', $modelInstance);
        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'store',
                'model' => $this->modelClass,
                'error' => $e->getMessage(),
            ]);

            return $this->formatErrorResponse($e);
        }
    }

    public function show($id)
    {
        try {
            $startTime = microtime(true);
            $connection = DatabaseConnectionPool::getConnection();

            $query = $this->modelClass::query()->where('id', $id);
            $this->queryOptimizer->optimizeQuery($query, [
                'context' => 'show',
                'id' => $id
            ]);

            $result = $query->first();
            $duration = microtime(true) - $startTime;

            $this->queryMonitor->recordQuery(
                md5($query->toSql()),
                [
                    'duration' => $duration,
                    'query' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                    'count' => $result ? 1 : 0,
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => microtime(true)
                ]
            );

            DatabaseConnectionPool::releaseConnection($connection);

            if (!$result) {
                return $this->formatErrorResponse(null, 'Resource not found', 404);
            }

            return $this->formatSuccessResponse('show', $result);
        } catch (\Exception $e) {
            if (isset($connection)) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'show',
                'model' => $this->modelClass,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->formatErrorResponse($e);
        }
    }

    public function update(Request $request, $id)
    {
        $connection = null;
        $startTime = microtime(true);

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $query = $this->modelClass::query();
            $this->queryOptimizer->optimizeQuery($query, [
                'context' => 'find',
                'id' => $id
            ]);

            $modelInstance = $query->findOrFail($id);

            try {
                $validated = $request->validate($modelInstance->getValidationRules('update'));
            } catch (ValidationException $e) {
                return $this->handleValidationException($e);
            }

            $hookResponse = $this->runHook('beforeUpdate', $request, $validated, $modelInstance);
            if ($hookResponse) {
                return $hookResponse;
            }

            $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                $modelInstance
            );

            $duration = microtime(true) - $startTime;
            $this->queryMonitor->recordQuery(
                'update_' . get_class($modelInstance),
                [
                    'duration' => $duration,
                    'operation' => 'update',
                    'model' => get_class($modelInstance),
                    'id' => $id,
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => microtime(true)
                ]
            );

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);
            $this->runHook('afterUpdate', $request, $modelInstance);

            Event::dispatch(new EvolveModelUpdated($modelInstance));

            return $this->formatSuccessResponse('updated', $modelInstance->fresh());
        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'update',
                'model' => $this->modelClass,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->formatErrorResponse($e);
        }
    }

    public function destroy($id)
    {
        $connection = null;
        $startTime = microtime(true);

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $query = $this->modelClass::query();
            $this->queryOptimizer->optimizeQuery($query, [
                'context' => 'delete',
                'id' => $id
            ]);

            $modelInstance = $query->findOrFail($id);

            $hookResponse = $this->runHook('beforeDelete', request(), $modelInstance);
            if ($hookResponse) {
                return $hookResponse;
            }

            $modelInstance->delete();

            $duration = microtime(true) - $startTime;
            $this->queryMonitor->recordQuery(
                'delete_' . get_class($modelInstance),
                [
                    'duration' => $duration,
                    'operation' => 'delete',
                    'model' => get_class($modelInstance),
                    'id' => $id,
                    'memory_usage' => memory_get_usage(true),
                    'timestamp' => microtime(true)
                ]
            );

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);
            $this->runHook('afterDelete', request(), $modelInstance);
            Event::dispatch(new EvolveModelDeleted($modelInstance));

            return $this->formatSuccessResponse('deleted');
        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }
            $this->performanceBridge->rollbackTransaction();

            $this->queryMonitor->recordError([
                'operation' => 'destroy',
                'model' => $this->modelClass,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->formatErrorResponse($e);
        }
    }
    protected function handleValidationException(ValidationException $e): JsonResponse
    {
        $errorFormat = config('evolve.validation.error_format', 'default');

        $response = match($errorFormat) {
            'simple' => [
                'error' => 'Validation failed',
                'message' => $e->getMessage()
            ],
            'detailed' => [
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'failed_rules' => $e->validator->failed()
            ],
            'custom' => is_callable(config('evolve.validation.custom_formatter'))
                ? config('evolve.validation.custom_formatter')($e)
                : [
                    'error' => 'Validation failed',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors()
                ],
            default => [
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ]
        };

        return response()->json($response, 422);
    }

    protected function saveModelWithRelations($modelClass, array $data, $existingModel = null, bool $avoidDuplicates = false)
    {
        $startTime = microtime(true);
        $relations = array_keys($modelClass::getAllRelations());
        $attributes = array_diff_key($data, array_flip($relations));

        if ($avoidDuplicates) {
            $uniqueFields = $modelClass::uniqueFields();
            $query = $modelClass::query();

            $this->queryOptimizer->optimizeQuery($query, [
                'context' => 'unique_check',
                'fields' => $uniqueFields
            ]);

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
        $this->queryMonitor->recordQuery(
            'save_relations_' . get_class($modelInstance),
            [
                'duration' => $duration,
                'operation' => 'save_relations',
                'model' => get_class($modelInstance),
                'relation_count' => count($relations),
                'memory_usage' => memory_get_usage(true),
                'timestamp' => microtime(true)
            ]
        );

        return $modelInstance;
    }

    protected function processOptimizedRelation($modelInstance, string $relation, array $relationData, bool $avoidDuplicates): void
    {
        $startTime = microtime(true);
        $relationMethod = $modelInstance->$relation();
        $relatedModelClass = get_class($relationMethod->getRelated());

        $this->queryOptimizer->optimizeQuery($relationMethod->getQuery(), [
            'context' => 'relation_load',
            'relation' => $relation,
            'parent_model' => get_class($modelInstance)
        ]);

        if ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            $this->processBelongsToManyRelation($relationMethod, $relationData, $avoidDuplicates);
        } else {
            $this->processRegularRelation($relationMethod, $relationData, $avoidDuplicates, $relatedModelClass);
        }

        $duration = microtime(true) - $startTime;
        $this->queryMonitor->recordQuery(
            'process_relation_' . $relation,
            [
                'duration' => $duration,
                'operation' => 'process_relation',
                'relation' => $relation,
                'model' => get_class($modelInstance),
                'related_model' => $relatedModelClass,
                'data_count' => count($relationData),
                'memory_usage' => memory_get_usage(true),
                'timestamp' => microtime(true)
            ]
        );
    }

    protected function processBelongsToManyRelation($relationMethod, array $relationData, bool $avoidDuplicates): void
    {
        $startTime = microtime(true);
        $relatedIds = [];
        $relatedModelClass = get_class($relationMethod->getRelated());

        $this->queryOptimizer->optimizeQuery($relationMethod->newPivotQuery(), [
            'context' => 'pivot_operation',
            'relation_type' => 'belongs_to_many'
        ]);

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

        $duration = microtime(true) - $startTime;
        $this->queryMonitor->recordQuery(
            'belongs_to_many_sync',
            [
                'duration' => $duration,
                'operation' => 'sync',
                'relation_type' => 'belongs_to_many',
                'model' => $relatedModelClass,
                'ids_count' => count($relatedIds),
                'memory_usage' => memory_get_usage(true),
                'timestamp' => microtime(true)
            ]
        );
    }

    protected function processRegularRelation($relationMethod, array $relationData, bool $avoidDuplicates, string $relatedModelClass): void
    {
        $startTime = microtime(true);
        foreach (array_chunk($relationData, 1000) as $chunk) {
            $relatedModels = [];

            foreach ($chunk as $itemData) {
                $existingRelatedModel = null;

                if ($avoidDuplicates) {
                    $uniqueFields = $relatedModelClass::uniqueFields();
                    $query = $relatedModelClass::query();

                    $this->queryOptimizer->optimizeQuery($query, [
                        'context' => 'unique_check',
                        'fields' => $uniqueFields
                    ]);

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

            $saveQuery = $relationMethod->getQuery()->getModel()->newQuery();
            $this->queryOptimizer->optimizeQuery($saveQuery, [
                'context' => 'save_many',
                'batch_size' => count($relatedModels)
            ]);

            $relationMethod->saveMany($relatedModels);
        }

        $duration = microtime(true) - $startTime;
        $this->queryMonitor->recordQuery(
            'regular_relation_save',
            [
                'duration' => $duration,
                'operation' => 'save_many',
                'model' => $relatedModelClass,
                'total_records' => count($relationData),
                'memory_usage' => memory_get_usage(true),
                'timestamp' => microtime(true)
            ]
        );
    }

    protected function runHook(string $hookName, Request $request, &$data, $modelInstance = null)
    {
        $hookClass = $this->getHookClass();

        if ($hookClass && method_exists($hookClass, $hookName)) {
            try {
                $startTime = microtime(true);
                $result = $hookClass->{$hookName}($request, $data, $modelInstance);

                $duration = microtime(true) - $startTime;
                $this->queryMonitor->recordQuery(
                    "hook_{$hookName}",
                    [
                        'duration' => $duration,
                        'operation' => 'hook',
                        'hook_name' => $hookName,
                        'model' => $this->modelClass,
                        'memory_usage' => memory_get_usage(true),
                        'timestamp' => microtime(true)
                    ]
                );

                return $result;
            } catch (\Exception $e) {
                $this->queryMonitor->recordError([
                    'operation' => "hook_{$hookName}",
                    'model' => $this->modelClass,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return null;
    }

    protected function getHookClass()
    {
        $hookClassName = 'App\\Http\\Controllers\\Api\\' . class_basename($this->modelClass) . 'Controller';

        if (class_exists($hookClassName)) {
            return new $hookClassName();
        }

        return null;
    }

    protected function formatSuccessResponse(string $action, $data = null): JsonResponse
    {
        $responseFormat = config('evolve.api.response_format', 'default');

        $response = match($responseFormat) {
            'simple' => $data,
            'custom' => is_callable(config('evolve.api.custom_formatter'))
                ? config('evolve.api.custom_formatter')($action, $data, true)
                : ['success' => true, 'data' => $data],
            default => [
                'success' => true,
                'message' => "Resource {$action} successfully",
                'data' => $data
            ]
        };

        return response()->json($response, $action === 'created' ? 201 : 200);
    }

    protected function formatErrorResponse(\Exception $e = null, string $message = null, int $status = 500): JsonResponse
    {
        $responseFormat = config('evolve.api.response_format', 'default');

        $response = match($responseFormat) {
            'simple' => [
                'error' => $message ?? $e?->getMessage() ?? 'An error occurred'
            ],
            'custom' => is_callable(config('evolve.api.custom_formatter'))
                ? config('evolve.api.custom_formatter')(null, $e, false)
                : [
                    'success' => false,
                    'error' => $message ?? $e?->getMessage() ?? 'An error occurred'
                ],
            default => [
                'success' => false,
                'message' => $message ?? 'An error occurred',
                'error' => $e?->getMessage(),
                'errors' => $e instanceof ValidationException ? $e->errors() : null
            ]
        };

        return response()->json($response, $e instanceof ValidationException ? 422 : $status);
    }
}

