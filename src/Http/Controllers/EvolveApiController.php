<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Thinkneverland\Evolve\Core\Events\EvolveModelCreated;
use Thinkneverland\Evolve\Core\Events\EvolveModelUpdated;
use Thinkneverland\Evolve\Core\Events\EvolveModelDeleted;
use Thinkneverland\Evolve\Core\Support\QueryPerformanceBridge;
use Thinkneverland\Evolve\Core\Support\QueryMonitor;
use Thinkneverland\Evolve\Core\Support\DatabaseConnectionPool;

class EvolveApiController extends Controller
{
    protected $modelClass;
    protected $performanceBridge;
    protected $queryMonitor;

    public function __construct(Request $request)
    {
        $this->modelClass = $request->route('modelClass');
        $this->performanceBridge = QueryPerformanceBridge::getInstance();
        $this->queryMonitor = QueryMonitor::getInstance();
    }

    /**
     * Display a listing of the resource with optimized query handling.
     */
    public function index(Request $request)
    {
        $filters = $request->input('filter', []);
        $sorts = $request->input('sort', null);
        $perPage = $request->input('per_page', 15);

        try {
            // Get optimized connection from pool
            $connection = DatabaseConnectionPool::getConnection();

            // Create query using performance bridge
            $query = $this->modelClass::query();

            // Apply evolve optimization (combines filter and sort)
            $query = $this->modelClass::evolve($filters, $sorts);

            // Execute optimized query with monitoring
            $result = $this->performanceBridge->executeOptimizedQuery($query, [
                'context' => 'index',
                'filters' => $filters,
                'sorts' => $sorts,
                'per_page' => $perPage
            ]);

            // Handle duplicates and pagination
            $data = collect($result)
                ->unique()
                ->values()
                ->paginate($perPage);

            DatabaseConnectionPool::releaseConnection($connection);

            return response()->json($data);
        } catch (\Exception $e) {
            DatabaseConnectionPool::releaseConnection($connection);
            $this->queryMonitor->recordError([
                'operation' => 'index',
                'model' => $this->modelClass,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to fetch resources', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource with transaction and validation optimization.
     */
    public function store(Request $request)
    {
        $avoidDuplicates = $request->boolean('avoid_duplicates', false);
        $connection = null;

        try {
            // Begin optimized transaction
            $this->performanceBridge->beginTransaction();

            // Get connection from pool
            $connection = DatabaseConnectionPool::getConnection();

            // Validate with optimized rules
            $validated = $request->validate($this->modelClass::getValidationRules('create'));

            // Process beforeCreate hook if available
            $hookResponse = $this->runHook('beforeCreate', $request, $validated);
            if ($hookResponse) return $hookResponse;

            // Save model with optimized relation handling
            $modelInstance = $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                null,
                $avoidDuplicates
            );

            // Process afterCreate hook
            $this->runHook('afterCreate', $request, $modelInstance);

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            Event::dispatch(new EvolveModelCreated($modelInstance));

            return response()->json($modelInstance, 201);
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

            return response()->json([
                'error' => 'Failed to create resource',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Update the specified resource with optimized validation and relations.
     */
    public function update(Request $request, $id)
    {
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($id);

            // Get optimized validation rules
            $validated = $request->validate(
                $this->modelClass::getValidationRules('update', $modelInstance)
            );

            // Process beforeUpdate hook
            $hookResponse = $this->runHook('beforeUpdate', $request, $validated, $modelInstance);
            if ($hookResponse) return $hookResponse;

            // Update model with optimized relation handling
            $this->saveModelWithRelations(
                $this->modelClass,
                $validated,
                $modelInstance
            );

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            // Process afterUpdate hook
            $this->runHook('afterUpdate', $request, $modelInstance);

            Event::dispatch(new EvolveModelUpdated($modelInstance));

            return response()->json([
                'success' => true,
                'data' => $modelInstance->fresh(),
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
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update resource.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource with optimized transaction handling.
     */
    public function destroy($id)
    {
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($id);

            // Process beforeDelete hook
            $hookResponse = $this->runHook('beforeDelete', request(), $modelInstance);
            if ($hookResponse) return $hookResponse;

            $modelInstance->delete();

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            // Process afterDelete hook
            $this->runHook('afterDelete', request(), $modelInstance);

            Event::dispatch(new EvolveModelDeleted($modelInstance));

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
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete resource.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource with optimized query handling.
     */
    public function show($id)
    {
        try {
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->performanceBridge->executeOptimizedQuery(
                $this->modelClass::where('id', $id),
                ['context' => 'show']
            );

            DatabaseConnectionPool::releaseConnection($connection);

            if (empty($modelInstance)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $modelInstance[0],
                'message' => null
            ]);
        } catch (\Exception $e) {
            DatabaseConnectionPool::releaseConnection($connection);

            $this->queryMonitor->recordError([
                'operation' => 'show',
                'model' => $this->modelClass,
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch resource.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save model with optimized relation handling.
     */
    protected function saveModelWithRelations($modelClass, array $data, $existingModel = null, bool $avoidDuplicates = false)
    {
        // Extract relations for optimized processing
        $relations = array_keys($modelClass::getAllRelations());
        $attributes = array_diff_key($data, array_flip($relations));

        if ($avoidDuplicates) {
            $uniqueFields = $modelClass::uniqueFields();
            $query = $modelClass::query();

            foreach ($uniqueFields as $field) {
                if (isset($attributes[$field])) {
                    $query->where($field, $attributes[$field]);
                }
            }

            $existingModel = $query->first();
        }

        // Create or update model
        if ($existingModel) {
            $existingModel->update($attributes);
            $modelInstance = $existingModel;
        } else {
            $modelInstance = $modelClass::create($attributes);
        }

        // Process relations in optimized batches
        foreach ($relations as $relation) {
            if (isset($data[$relation])) {
                $this->processOptimizedRelation($modelInstance, $relation, $data[$relation], $avoidDuplicates);
            }
        }

        return $modelInstance;
    }

    /**
     * Process relations with optimization.
     */
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

    /**
     * Process BelongsToMany relations with optimization.
     */
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

    /**
     * Process regular relations with optimization.
     */
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

    /**
     * Run hook methods with performance monitoring.
     */
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
                throw $e;
            }
        }

        return null;
    }

    /**
     * Get the hook class if it exists.
     */
    protected function getHookClass()
    {
        $hookClassName = 'App\\Http\\Controllers\\Api\\' . class_basename($this->modelClass) . 'Controller';

        if (class_exists($hookClassName)) {
            return new $hookClassName;
        }

        return null;
    }
}
