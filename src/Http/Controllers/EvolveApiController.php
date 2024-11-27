<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Thinkneverland\Evolve\Core\Events\EvolveModelCreated;
use Thinkneverland\Evolve\Core\Events\EvolveModelUpdated;
use Thinkneverland\Evolve\Core\Events\EvolveModelDeleted;
use Thinkneverland\Evolve\Core\Support\ModelRegistry;
use Thinkneverland\Evolve\Core\Support\QueryPerformanceBridge;
use Thinkneverland\Evolve\Core\Support\QueryMonitor;
use Thinkneverland\Evolve\Core\Support\DatabaseConnectionPool;
use Thinkneverland\Evolve\Core\Support\ErrorResponseFormatter;

class EvolveApiController extends Controller
{
    protected $modelClass;
    protected $performanceBridge;
    protected $queryMonitor;

    public function __construct(Request $request)
    {
        $modelAlias = $request->route('modelClass');
        $this->modelClass = ModelRegistry::getModelClassByIdentifier($modelAlias);

        if (! $this->modelClass) {
            abort(400, "Model class not found for identifier '{$modelAlias}'.");
        }

        $this->performanceBridge = QueryPerformanceBridge::getInstance();
        $this->queryMonitor = QueryMonitor::getInstance();
    }

    /**
     * Display a listing of the resource with optimized query handling.
     */
    public function index(Request $request)
    {
        $filters    = $request->input('filter', []);
        $sorts      = $request->input('sort', null);
        $perPage    = $request->input('per_page', 15);
        $connection = null;

        try {
            // Get optimized connection from pool
            $connection = DatabaseConnectionPool::getConnection();

            // Build query using model's evolve method
            $query = $this->modelClass::evolve($filters, $sorts);

            // Paginate the query
            $paginator = $query->paginate($perPage);

            // Load relationships for each model instance
            $paginator->getCollection()->each(function ($modelInstance) {
                $modelInstance->load($modelInstance->getAllRelations());
            });

            // Transform each model instance to DTO
            $paginator->setCollection(
                $paginator->getCollection()->map(function ($modelInstance) {
                    return $modelInstance->toDTO();
                })
            );

            DatabaseConnectionPool::releaseConnection($connection);

            return response()->json([
                'success' => true,
                'data'    => $paginator,
                'message' => null,
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'index',
                'model'     => $this->modelClass,
                'error'     => $e->getMessage(),
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

    /**
     * Store a newly created resource with enhanced error handling
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

            // Instantiate the model
            $modelInstance = new $this->modelClass;

            // Validate with optimized rules
            try {
                $validated = $request->validate($modelInstance->getValidationRules('create'));
            } catch (ValidationException $e) {
                return response()->json(
                    ErrorResponseFormatter::formatValidationError($e),
                    ErrorResponseFormatter::getErrorStatusCode($e)
                );
            }

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

            $modelInstance->load($modelInstance->getAllRelations());

            Event::dispatch(new EvolveModelCreated($modelInstance));

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

    /**
     * Display the specified resource with optimized query handling.
     */
    public function show($model, $id)
    {
        $connection = null;

        try {
            $connection = DatabaseConnectionPool::getConnection();

            // Retrieve the model instance
            $modelInstance = $this->modelClass::findOrFail($id);

            // Load all relationships
            $modelInstance->load($modelInstance->getAllRelations());

            DatabaseConnectionPool::releaseConnection($connection);

            return response()->json([
                'success' => true,
                'data'    => $modelInstance->toDTO(),
                'message' => null
            ]);

        } catch (\Exception $e) {
            if ($connection) {
                DatabaseConnectionPool::releaseConnection($connection);
            }

            $this->queryMonitor->recordError([
                'operation' => 'show',
                'model'     => $this->modelClass,
                'id'        => $id,
                'error'     => $e->getMessage()
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

    /**
     * Update the specified resource with enhanced error handling
     */
    public function update(Request $request, $id)
    {
        $connection = null;

        try {
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            $modelInstance = $this->modelClass::findOrFail($id);

            try {
                $validated = $request->validate(
                    $this->modelClass::getValidationRules('update', $modelInstance)
                );
            } catch (ValidationException $e) {
                return response()->json(
                    ErrorResponseFormatter::formatValidationError($e),
                    ErrorResponseFormatter::getErrorStatusCode($e)
                );
            }

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

            $modelInstance->load($modelInstance->getAllRelations());

            // Process afterUpdate hook
            $this->runHook('afterUpdate', $request, $modelInstance);

            Event::dispatch(new EvolveModelUpdated($modelInstance));

            return response()->json([
                'success' => true,
                'data' => $modelInstance->toDto(),
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

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to update resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    /**
     * Remove the specified resource with optimized transaction handling.
     */
    public function destroy($model,$id)
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

            return response()->json(
                ErrorResponseFormatter::formatError(
                    $e,
                    'Failed to delete resource. Please try again later.'
                ),
                ErrorResponseFormatter::getErrorStatusCode($e)
            );
        }
    }

    /**
     * Save model with optimized relation handling.
     */
    protected function saveModelWithRelations($modelClass, array $data, $existingModel = null, bool $avoidDuplicates = false)
    {
        // Create an instance of the model
        $modelInstance = new $modelClass;

        // Extract relations for optimized processing
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
