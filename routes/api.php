<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Thinkneverland\Evolve\Core\Contracts\EvolveModelInterface;
use Illuminate\Support\Facades\File;

$models = [];
$modelPath = app_path('Models');

foreach (File::allFiles($modelPath) as $file) {
    $namespace = app()->getNamespace() . 'Models\\';
    $class = $namespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

    if (class_exists($class) && in_array(EvolveModelInterface::class, class_implements($class))) {
        if ($class::shouldEvolve()) {
            $models[] = $class;
        }
    }
}

foreach ($models as $modelClass) {
    $modelSlug = Str::plural(Str::kebab(class_basename($modelClass)));
    $controllerClass = 'Evolve\\Api\\Http\\Controllers\\EvolveApiController';

    Route::post("evolve/{$modelSlug}", [$controllerClass, 'store'])
        ->name("{$modelSlug}.store")
        ->defaults('modelClass', $modelClass);

    Route::get("evolve/{$modelSlug}", [$controllerClass, 'index'])
        ->name("{$modelSlug}.index")
        ->defaults('modelClass', $modelClass);

    Route::get("evolve/{$modelSlug}/{id}", [$controllerClass, 'show'])
        ->name("{$modelSlug}.show")
        ->defaults('modelClass', $modelClass);

    Route::put("evolve/{$modelSlug}/{id}", [$controllerClass, 'update'])
        ->name("{$modelSlug}.update")
        ->defaults('modelClass', $modelClass);

    Route::delete("evolve/{$modelSlug}/{id}", [$controllerClass, 'destroy'])
        ->name("{$modelSlug}.destroy")
        ->defaults('modelClass', $modelClass);
}
