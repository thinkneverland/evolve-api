<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\{DB, Event, Log};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
use Thinkneverland\Evolve\Core\Services\QueryBuilderService;
use Thinkneverland\Evolve\Core\Facades\EvolveLog;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

class EvolveApiController extends Controller
{
    protected ?string $modelClass = null;
    protected string $resource;
    protected QueryPerformanceBridge $performanceBridge;
    protected QueryMonitor $queryMonitor;
    protected QueryBuilderService $queryBuilder;

    /**
     * Constructor
     */
    public function __construct(Request $request)
    {
        $this->performanceBridge = QueryPerformanceBridge::getInstance();
        $this->queryMonitor = new QueryMonitor();
        $this->queryBuilder = new QueryBuilderService();
    }

    /**
     * Initialize the model class based on the resource parameter
     */
    protected function initializeModelClass(string $resource): void
    {
        if ($this->modelClass === null) {
            $modelClass = ModelRegistry::getModelClassByIdentifier($resource);
            if (!$modelClass || !class_exists($modelClass)) {
                throw new \InvalidArgumentException("Invalid resource type: {$resource}");
            }
            
            // Verify it's a concrete class that extends Model
            $reflection = new \ReflectionClass($modelClass);
            if ($reflection->isAbstract()) {
                throw new \RuntimeException("Model class {$modelClass} is abstract and cannot be instantiated");
            }
            if (!$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                throw new \RuntimeException("Class {$modelClass} must extend Illuminate\\Database\\Eloquent\\Model");
            }
            
            $this->modelClass = $modelClass;
        }
    }

    /**
     * Get model instance for static operations
     */
    protected function getModelInstance(): object
    {
        if (!$this->modelClass) {
            throw new \RuntimeException('Model class has not been initialized');
        }
        
        // Create a new instance of the model class
        return new $this->modelClass();
    }

    /**
     * Get the resource name for the current request
     */
    protected function getResourceName(): string
    {
        return Str::singular($this->resource);
    }

    /**
     * Find a model by ID
     *
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id, bool $withTrashed = false): object
    {
        if (!$this->modelClass) {
            throw new NotFoundHttpException('Model class not initialized');
        }

        $model = $this->getModelInstance();
        $query = $model->newQuery();
        
        // Check if model supports soft deletes
        $supportsSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive(get_class($model)));
        
        if ($supportsSoftDeletes) {
            // Always include trashed in the initial query to check status
            $trashedQuery = clone $query;
            $record = $trashedQuery->withTrashed()->find($id);
            
            if (!$record) {
                throw new NotFoundHttpException(
                    "No " . $this->getResourceName() . " found with ID {$id}"
                );
            }
            
            if ($record->trashed() && !$withTrashed) {
                // Load relations before returning
                $this->loadModelRelations($record);
                
                $data = method_exists($record, 'toDTO') ? $record->toDTO() : $record->toArray();
                
                return response()->json([
                    'success' => false,
                    'message' => "The requested " . $this->getResourceName() . " with ID {$id} has been deleted",
                    'data' => $data
                ], 404);
            }
            
            return $withTrashed ? $record : $query->find($id);
        }
        
        // If model doesn't support soft deletes, just do a normal find
        return $query->findOrFail($id);
    }

    /**
     * Load model relations
     */
    protected function loadModelRelations($model): void
    {
        if (method_exists($model, 'getAllRelations')) {
            $relations = $model->getAllRelations();
            
            if (!empty($relations)) {
                if (method_exists($model, 'excludedRelations')) {
                    $excluded = $model->excludedRelations();
                    $relations = array_diff($relations, $excluded);
                }
                
                $model->load($relations);
            }
        }
    }

    /**
     * Get model instance
     */
    protected function getModel(): string
    {
        if (!$this->modelClass) {
            throw new \RuntimeException('Model class has not been initialized');
        }
        return $this->modelClass;
    }

    /**
     * Check if we're currently listing routes via artisan command
     */
    protected function isListingRoutes(): bool
    {
        return app()->runningInConsole() &&
            (!isset($_SERVER['argv'][1]) || $_SERVER['argv'][1] === 'route:list');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $resource): JsonResponse
    {
        try {
            $this->resource = $resource;
            $this->initializeModelClass($resource);

            if (!$this->modelClass) {
                throw new NotFoundHttpException("Invalid resource type: {$resource}");
            }

            $model = $this->getModelInstance();
            $query = $model->newQuery();

            // Handle soft deletes
            if (in_array(SoftDeletes::class, class_uses_recursive(get_class($model)))) {
                if ($request->boolean('with_trashed', false)) {
                    $query->withTrashed();
                } elseif ($request->boolean('only_trashed', false)) {
                    $query->onlyTrashed();
                }
            }

            // Apply filters if provided
            if ($filters = $request->input('filters')) {
                $query->filter($filters);
            }

            // Apply sorting
            if ($sorts = $request->input('sort')) {
                // Convert string sort parameter to array format
                if (is_string($sorts)) {
                    $sortArray = [];
                    foreach (explode(',', $sorts) as $sort) {
                        $direction = 'asc';
                        if (str_starts_with($sort, '-')) {
                            $direction = 'desc';
                            $sort = substr($sort, 1);
                        }
                        $sortArray[$sort] = $direction;
                    }
                    $sorts = $sortArray;
                }
                
                $query->evolve(null, $sorts);
            }

            // Handle pagination
            $perPage = min($request->input('per_page', 15), 100);
            $paginator = $query->paginate($perPage);

            // Return 404 if no results found
            if ($paginator->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "No " . str($this->getResourceName())->plural() . " found matching the criteria",
                    'data' => []
                ], 404);
            }

            // Load relations
            $paginator->getCollection()->each(function ($modelInstance) {
                $this->loadModelRelations($modelInstance);
            });

            // Convert to DTOs if available
            $items = $paginator->getCollection()->map(function ($modelInstance) {
                return method_exists($modelInstance, 'toDTO') ? $modelInstance->toDTO() : $modelInstance->toArray();
            });

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'from' => $paginator->firstItem(),
                    'last_page' => $paginator->lastPage(),
                    'path' => $paginator->path(),
                    'per_page' => $paginator->perPage(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
                'message' => null
            ]);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $resource): JsonResponse
    {
        try {
            $this->resource = $resource;
            $this->initializeModelClass($resource);

            if (!$this->modelClass) {
                throw new NotFoundHttpException("Invalid resource type: {$resource}");
            }

            $model = $this->getModelInstance();
            
            // Get validation rules from the model
            $rules = $model::getValidationRules('create');
            
            // Validate the request
            $validatedData = $request->validate($rules);

            // Process related searches
            $validatedData = $this->processRelatedSearch($validatedData);

            // Begin transaction
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            // Create the model with validated data
            $modelInstance = $model->newInstance($validatedData);
            
            // Save the model and handle nested relations
            $modelInstance->save();
            
            // Handle nested relations if they exist in the request
            if ($relations = $request->input('relations')) {
                foreach ($relations as $relation => $data) {
                    if (method_exists($modelInstance, $relation)) {
                        $relationType = get_class($modelInstance->$relation());
                        
                        // Handle different relation types
                        switch (class_basename($relationType)) {
                            case 'HasOne':
                            case 'HasMany':
                                $modelInstance->$relation()->createMany((array) $data);
                                break;
                                
                            case 'BelongsTo':
                                if (is_array($data)) {
                                    $related = $modelInstance->$relation()->getRelated();
                                    $relatedInstance = $related->create($data);
                                    $modelInstance->$relation()->associate($relatedInstance);
                                } else {
                                    $modelInstance->$relation()->associate($data);
                                }
                                break;
                                
                            case 'BelongsToMany':
                                if (is_array($data)) {
                                    $modelInstance->$relation()->attach(array_column($data, 'id'), 
                                        array_column($data, 'pivot', []));
                                } else {
                                    $modelInstance->$relation()->attach($data);
                                }
                                break;
                        }
                    }
                }
                
                // Save any relation changes
                $modelInstance->save();
            }

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            // Load all non-excluded relations
            $this->loadModelRelations($modelInstance);

            // Fire the created event
            Event::dispatch(new EvolveModelCreated($modelInstance));

            return response()->json([
                'success' => true,
                'data' => method_exists($modelInstance, 'toDTO') ? $modelInstance->toDTO() : $modelInstance->toArray(),
                'message' => $this->getResourceName() . " created successfully"
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(
                ErrorResponseFormatter::formatValidationError($e),
                422
            );
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id, string $resource): JsonResponse
    {
        try {
            $this->resource = $resource;
            $this->initializeModelClass($resource);
            
            if (!$this->modelClass) {
                throw new NotFoundHttpException("Invalid resource type: {$resource}");
            }

            $result = $this->findModel($id, $request->boolean('with_trashed', false));
            
            // If the result is already a JsonResponse (in case of soft deleted record)
            if ($result instanceof JsonResponse) {
                return $result;
            }
            
            $model = $result;
            
            // Load relations
            $this->loadModelRelations($model);

            $data = method_exists($model, 'toDTO') ? $model->toDTO() : $model->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => null
            ]);

        } catch (NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            EvolveLog::error("Error in show method", [
                'resource' => $resource,
                'id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id, string $resource): JsonResponse
    {
        try {
            $this->resource = $resource;
            $this->initializeModelClass($resource);

            if (!$this->modelClass) {
                throw new NotFoundHttpException("Invalid resource type: {$resource}");
            }

            // Find the model
            $modelInstance = $this->findModel($id);
            if ($modelInstance instanceof JsonResponse) {
                return $modelInstance;
            }

            // Get validation rules from the model
            $rules = $modelInstance::getValidationRules('update');
            
            // Validate the request
            $validatedData = $request->validate($rules);

            // Process related searches
            $validatedData = $this->processRelatedSearch($validatedData);

            // Begin transaction
            $this->performanceBridge->beginTransaction();
            $connection = DatabaseConnectionPool::getConnection();

            // Update the model with validated data
            $modelInstance->fill($validatedData);
            
            // Save the model
            $modelInstance->save();
            
            // Handle nested relations if they exist in the request
            if ($relations = $request->input('relations')) {
                foreach ($relations as $relation => $data) {
                    if (method_exists($modelInstance, $relation)) {
                        $relationType = get_class($modelInstance->$relation());
                        
                        // Handle different relation types
                        switch (class_basename($relationType)) {
                            case 'HasOne':
                                if ($data === null) {
                                    $modelInstance->$relation()->delete();
                                } else {
                                    $modelInstance->$relation()->updateOrCreate([], $data);
                                }
                                break;
                                
                            case 'HasMany':
                                if (empty($data)) {
                                    $modelInstance->$relation()->delete();
                                } else {
                                    // Get existing records
                                    $existing = $modelInstance->$relation()->pluck('id')->all();
                                    $updated = array_column($data, 'id');
                                    
                                    // Delete removed records
                                    $toDelete = array_diff($existing, $updated);
                                    if (!empty($toDelete)) {
                                        $modelInstance->$relation()->whereIn('id', $toDelete)->delete();
                                    }
                                    
                                    // Update or create records
                                    foreach ($data as $item) {
                                        $modelInstance->$relation()->updateOrCreate(
                                            ['id' => $item['id'] ?? null],
                                            $item
                                        );
                                    }
                                }
                                break;
                                
                            case 'BelongsTo':
                                if ($data === null) {
                                    $modelInstance->$relation()->dissociate();
                                } elseif (is_array($data)) {
                                    $related = $modelInstance->$relation()->getRelated();
                                    $relatedInstance = $related->updateOrCreate(
                                        ['id' => $data['id'] ?? null],
                                        $data
                                    );
                                    $modelInstance->$relation()->associate($relatedInstance);
                                } else {
                                    $modelInstance->$relation()->associate($data);
                                }
                                break;
                                
                            case 'BelongsToMany':
                                if (empty($data)) {
                                    $modelInstance->$relation()->detach();
                                } else {
                                    $sync = [];
                                    foreach ($data as $item) {
                                        if (is_array($item)) {
                                            $sync[$item['id']] = array_except($item, ['id']);
                                        } else {
                                            $sync[] = $item;
                                        }
                                    }
                                    $modelInstance->$relation()->sync($sync);
                                }
                                break;
                        }
                    }
                }
                
                // Save any relation changes
                $modelInstance->save();
            }

            $this->performanceBridge->commitTransaction();
            DatabaseConnectionPool::releaseConnection($connection);

            // Load all non-excluded relations
            $this->loadModelRelations($modelInstance);

            // Fire the updated event
            Event::dispatch(new EvolveModelUpdated($modelInstance));

            return response()->json([
                'success' => true,
                'data' => method_exists($modelInstance, 'toDTO') ? $modelInstance->toDTO() : $modelInstance->toArray(),
                'message' => $this->getResourceName() . " updated successfully"
            ]);

        } catch (ValidationException $e) {
            return response()->json(
                ErrorResponseFormatter::formatValidationError($e),
                422
            );
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id, string $resource): JsonResponse
    {
        $this->initializeModelClass($resource);
        
        try {
            $modelInstance = $this->findModel($id);

            $hookResponse = $this->runHook('beforeDelete', request(), $modelInstance);
            if ($hookResponse) return $hookResponse;

            // Check if model uses soft deletes
            if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive($modelInstance))) {
                if (!$modelInstance->trashed()) {
                    try {
                        $modelInstance->delete();
                        $message = 'Resource soft deleted successfully.';
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Check for foreign key constraint violation
                        if ($e->getCode() === '23000') {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot delete this resource as it is referenced by other records.',
                                'error' => [
                                    'type' => 'DependencyConstraintViolation',
                                    'message' => 'This resource has dependent records that must be handled first.'
                                ]
                            ], 409);
                        }
                        throw $e;
                    }
                } else {
                    // If already soft deleted and force flag is true, force delete
                    if ($request->boolean('force', false)) {
                        // Check for dependent relationships
                        if ($this->hasDependentRelations($modelInstance)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot permanently delete this resource as it is referenced by other records.',
                                'error' => [
                                    'type' => 'DependencyConstraintViolation',
                                    'message' => 'This resource has dependent records that must be handled first.'
                                ]
                            ], 409);
                        }
                        $modelInstance->forceDelete();
                        $message = 'Resource permanently deleted successfully.';
                    } else {
                        return response()->json([
                            'success' => false,
                            'message' => 'Resource is already soft deleted. Use force=true to permanently delete.',
                            'error' => [
                                'type' => 'ResourceAlreadyDeleted',
                                'message' => 'Resource was previously soft deleted.'
                            ]
                        ], 400);
                    }
                }
            } else {
                // For non-soft-delete models, check dependencies before deleting
                if ($this->hasDependentRelations($modelInstance)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete this resource as it is referenced by other records.',
                        'error' => [
                            'type' => 'DependencyConstraintViolation',
                            'message' => 'This resource has dependent records that must be handled first.'
                        ]
                    ], 409);
                }
                try {
                    $modelInstance->delete();
                    $message = 'Resource deleted successfully.';
                } catch (\Illuminate\Database\QueryException $e) {
                    // Check for foreign key constraint violation
                    if ($e->getCode() === '23000') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot delete this resource as it is referenced by other records.',
                            'error' => [
                                'type' => 'DependencyConstraintViolation',
                                'message' => 'This resource has dependent records that must be handled first.'
                            ]
                        ], 409);
                    }
                    throw $e;
                }
            }

            $hookResponse = $this->runHook('afterDelete', request(), $modelInstance);
            if ($hookResponse) return $hookResponse;

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'error' => [
                    'type' => 'ResourceNotFound',
                    'message' => 'The requested resource could not be found.'
                ]
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the resource.',
                'error' => [
                    'type' => class_basename($e),
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Check if model has dependent relations that would prevent deletion
     */
    protected function hasDependentRelations($model): bool
    {
        try {
            DB::beginTransaction();
            $model->delete();
            DB::rollBack();
            return false;
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            return $e->getCode() === '23000'; // Foreign key constraint violation
        }
    }

    /**
     * Save model with its relations
     */
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

    /**
     * Process optimized relation
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
     * Process BelongsToMany relation
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
     * Process regular relation
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
     * Record metrics for monitoring
     */
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
                    'request_id' => $this->request->route('id'),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * Record query metrics for monitoring
     */
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
                        'request_id' => $this->request->route('id'),
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
                        'request_id' => $this->request->route('id'),
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

    /**
     * Run model hook if it exists
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
     * Get the hook class if it exists
     */
    protected function getHookClass()
    {
        $hookClassName = 'App\\Http\\Controllers\\Api\\' . class_basename($this->modelClass) . 'Controller';

        if (class_exists($hookClassName)) {
            return new $hookClassName;
        }

        return null;
    }

    /**
     * Handle exceptions that occur during API operations
     *
     * @param \Exception $e
     * @param string $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleException(\Exception $e, string $message = 'An error occurred'): \Illuminate\Http\JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json(
                ErrorResponseFormatter::formatValidationError($e),
                422
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'Resource not found.',
            ], 404);
        }

        report($e);

        return response()->json(
            ErrorResponseFormatter::formatError($e, $message),
            500
        );
    }

    /**
     * Search for related models during create/update operations
     */
    protected function processRelatedSearch(array $data): array 
    {
        // Get excluded fields from model
        $model = $this->getModelInstance();
        $excludedFields = method_exists($model, 'getSearchExcludedFields') 
            ? $model->getSearchExcludedFields() 
            : ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($data as $key => $value) {
            // Skip excluded fields
            if (in_array($key, $excludedFields)) {
                continue;
            }

            // Check if this is a search request
            if (is_array($value) && isset($value['search'])) {
                $searchConfig = $value['search'];
                
                // Handle both string and array search configs
                if (is_string($searchConfig)) {
                    $searchConfig = ['term' => $searchConfig];
                }

                // Get relation name (remove _id suffix if present)
                $relation = preg_replace('/_id$/', '', $key);
                $modelClass = $this->getRelatedModelClass($relation);
                
                if (!$modelClass) {
                    continue;
                }

                $relatedModel = new $modelClass();
                $query = $relatedModel->newQuery();
                
                // Get searchable fields
                $searchableFields = method_exists($relatedModel, 'getSearchableFields') 
                    ? $relatedModel->getSearchableFields() 
                    : ['name']; // Default to name field

                // Apply search filters
                $query->where(function($q) use ($searchableFields, $searchConfig) {
                    if (isset($searchConfig['exact'])) {
                        foreach ($searchConfig['exact'] as $field => $term) {
                            $q->where($field, '=', $term);
                        }
                    }

                    if (isset($searchConfig['term'])) {
                        $term = $searchConfig['term'];
                        $q->where(function($subQ) use ($searchableFields, $term) {
                            foreach ($searchableFields as $field) {
                                $subQ->orWhere($field, 'LIKE', "%{$term}%");
                            }
                        });
                    }

                    if (isset($searchConfig['where'])) {
                        $this->applySearchConditions($q, $searchConfig['where']);
                    }
                });

                if (isset($searchConfig['orderBy'])) {
                    foreach ($searchConfig['orderBy'] as $field => $direction) {
                        $query->orderBy($field, $direction);
                    }
                }

                // Get all matches to check for multiple results
                $results = $query->get();
                
                if ($results->isEmpty()) {
                    throw new \InvalidArgumentException("No {$relation} found matching search criteria for field {$key}");
                }
                
                if ($results->count() > 1) {
                    $matchingIds = $results->pluck('id')->join(', ');
                    throw new \InvalidArgumentException(
                        "Multiple {$relation} records found matching search criteria for field {$key}. " .
                        "Found IDs: [{$matchingIds}]. Please refine your search criteria to match exactly one record."
                    );
                }

                $data[$key] = $results->first()->getKey();
            }
        }
        
        return $data;
    }

    protected function applySearchConditions($query, $conditions) 
    {
        foreach ($conditions as $condition) {
            if (!isset($condition['type'])) {
                $condition['type'] = 'where';
            }

            switch ($condition['type']) {
                case 'where':
                    if (isset($condition['field'], $condition['operator'], $condition['value'])) {
                        $query->where($condition['field'], $condition['operator'], $condition['value']);
                    }
                    break;

                case 'whereIn':
                    if (isset($condition['field'], $condition['values']) && is_array($condition['values'])) {
                        $query->whereIn($condition['field'], $condition['values']);
                    }
                    break;

                case 'whereNotIn':
                    if (isset($condition['field'], $condition['values']) && is_array($condition['values'])) {
                        $query->whereNotIn($condition['field'], $condition['values']);
                    }
                    break;

                case 'whereBetween':
                    if (isset($condition['field'], $condition['values']) && is_array($condition['values']) && count($condition['values']) === 2) {
                        $query->whereBetween($condition['field'], $condition['values']);
                    }
                    break;

                case 'whereNotBetween':
                    if (isset($condition['field'], $condition['values']) && is_array($condition['values']) && count($condition['values']) === 2) {
                        $query->whereNotBetween($condition['field'], $condition['values']);
                    }
                    break;

                case 'whereNull':
                    if (isset($condition['field'])) {
                        $query->whereNull($condition['field']);
                    }
                    break;

                case 'whereNotNull':
                    if (isset($condition['field'])) {
                        $query->whereNotNull($condition['field']);
                    }
                    break;

                case 'whereDate':
                    if (isset($condition['field'], $condition['operator'], $condition['value'])) {
                        $query->whereDate($condition['field'], $condition['operator'], $condition['value']);
                    }
                    break;

                case 'orWhere':
                    if (isset($condition['field'], $condition['operator'], $condition['value'])) {
                        $query->orWhere($condition['field'], $condition['operator'], $condition['value']);
                    }
                    break;

                case 'orWhereIn':
                    if (isset($condition['field'], $condition['values']) && is_array($condition['values'])) {
                        $query->orWhereIn($condition['field'], $condition['values']);
                    }
                    break;

                case 'whereHas':
                    if (isset($condition['relation'], $condition['where'])) {
                        $query->whereHas($condition['relation'], function($q) use ($condition) {
                            $this->applySearchConditions($q, $condition['where']);
                        });
                    }
                    break;

                case 'orWhereHas':
                    if (isset($condition['relation'], $condition['where'])) {
                        $query->orWhereHas($condition['relation'], function($q) use ($condition) {
                            $this->applySearchConditions($q, $condition['where']);
                        });
                    }
                    break;
            }
        }

        return $query;
    }

    /**
     * Get the related model class if it exists
     */
    protected function getRelatedModelClass(string $relation): ?string 
    {
        $model = $this->getModelInstance();
        if (!method_exists($model, $relation)) {
            return null;
        }

        $relationObj = $model->{$relation}();
        return get_class($relationObj->getRelated());
    }

    /**
     * Search for related models during create/update operations
     */
    public function searchRelated(Request $request, string $resource): JsonResponse
    {
        try {
            $this->resource = $resource;
            $this->initializeModelClass($resource);

            if (!$this->modelClass) {
                throw new NotFoundHttpException("Invalid resource type: {$resource}");
            }

            $model = $this->getModelInstance();
            $query = $model->newQuery();

            // Get search term
            $term = $request->input('term');
            if (empty($term)) {
                throw new \InvalidArgumentException("Search term is required");
            }

            // Get searchable fields from model
            $searchableFields = method_exists($model, 'getSearchableFields') 
                ? $model->getSearchableFields() 
                : ['name']; // Default to name field

            // Build search query
            $query->where(function($q) use ($searchableFields, $term) {
                foreach ($searchableFields as $field) {
                    $q->orWhere($field, 'LIKE', "%{$term}%");
                }
            });

            // Limit results
            $results = $query->limit(10)->get();

            // Transform results to simple key-value pairs
            $transformed = $results->map(function($item) {
                $label = method_exists($item, 'getSearchLabel') 
                    ? $item->getSearchLabel() 
                    : $item->name;
                    
                return [
                    'id' => $item->getKey(),
                    'label' => $label,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformed
            ]);

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}