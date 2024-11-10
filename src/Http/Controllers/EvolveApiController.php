<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Thinkneverland\Evolve\Core\Events\EvolveModelCreated;
use Thinkneverland\Evolve\Core\Events\EvolveModelUpdated;
use Thinkneverland\Evolve\Core\Events\EvolveModelDeleted;

class EvolveApiController extends Controller
{
    protected $modelClass;

    public function __construct(Request $request)
    {
        $this->modelClass = $request->route('modelClass');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $filters = $request->input('filter', []);
        $sorts = $request->input('sort', null);
        $perPage = $request->input('per_page', 15);

        $query = $this->modelClass::evolve($filters, $sorts);

        // Handle duplicates caused by joins in sorting
        $data = $query->distinct()->paginate($perPage);

        return response()->json($data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $avoidDuplicates = $request->boolean('avoid_duplicates', false);

        DB::beginTransaction();

        try {
            $modelInstance = $this->saveModelWithRelations(
                $this->modelClass,
                $request->all(),
                null,
                $avoidDuplicates
            );

            DB::commit();

            return response()->json($modelInstance, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to create resource', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param int     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $modelInstance = $this->modelClass::findOrFail($id);

        $validated = $request->validate($this->modelClass::getValidationRules('update', $modelInstance));

        // Process beforeUpdate hook if available
        $this->runHook('beforeUpdate', $request, $validated, $modelInstance);

        DB::beginTransaction();

        try {
            $this->saveModelWithRelations($this->modelClass, $validated, $modelInstance);

            DB::commit();

            // Process afterUpdate hook if available
            $this->runHook('afterUpdate', $request, $modelInstance);

            return response()->json([
                'success' => true,
                'data' => $modelInstance->fresh(), // Fetch fresh data with relations
                'message' => 'Resource updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update resource.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save the model with its relations.
     *
     * @param string $modelClass
     * @param array $data
     * @param mixed|null $existingModel
     * @param bool $avoidDuplicates
     * @return mixed
     */
    protected function saveModelWithRelations($modelClass, $data, $existingModel = null, $avoidDuplicates = false)
    {
        // Remove relations from data
        $relations = array_keys($modelClass::getAllRelations());
        $attributes = array_diff_key($data, array_flip($relations));

        if ($avoidDuplicates) {
            // Check for existing model based on unique fields
            $uniqueFields = $modelClass::uniqueFields();
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

        // Save relations
        foreach ($relations as $relation) {
            if (isset($data[$relation])) {
                $relationData = $data[$relation];
                $relationMethod = $modelInstance->$relation();

                if ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    $relatedIds = [];

                    foreach ($relationData as $itemData) {
                        $relatedModelClass = get_class($relationMethod->getRelated());
                        $relatedModel = $this->saveModelWithRelations(
                            $relatedModelClass,
                            $itemData,
                            null,
                            $avoidDuplicates
                        );
                        $relatedIds[] = $relatedModel->id;
                    }

                    $relationMethod->sync($relatedIds);
                } else {
                    foreach ($relationData as $itemData) {
                        $relatedModelClass = get_class($relationMethod->getRelated());
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

                        $relationMethod->save($relatedModel);
                    }
                }
            }
        }

        return $modelInstance;
    }

     /** Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $modelInstance = $this->modelClass::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $modelInstance,
            'message' => null,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $modelInstance = $this->modelClass::findOrFail($id);

        // Check for hooks
        $hookResponse = $this->runHook('beforeDelete', request(), $modelInstance);
        if ($hookResponse) {
            return $hookResponse;
        }

        DB::beginTransaction();

        try {
            $modelInstance->delete();

            DB::commit();

            Event::dispatch(new EvolveModelDeleted($modelInstance));

            $this->runHook('afterDelete', request(), $modelInstance);

            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Resource deleted successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete resource.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run hook methods if they exist.
     *
     * @param string       $hookName
     * @param Request      $request
     * @param mixed        &$data
     * @param mixed|null   $modelInstance
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function runHook(string $hookName, Request $request, &$data, $modelInstance = null)
    {
        $hookClass = $this->getHookClass();

        if ($hookClass && method_exists($hookClass, $hookName)) {
            return $hookClass->{$hookName}($request, $data, $modelInstance);
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
