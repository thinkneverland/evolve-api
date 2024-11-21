<?php

use Illuminate\Support\Facades\{File, Route};
use Illuminate\Support\Str;
use Thinkneverland\Evolve\Core\Contracts\EvolveModelInterface;

$models    = [];
$modelPath = app_path('Models');

foreach (File::allFiles($modelPath) as $file) {
    $namespace = app()->getNamespace() . 'Models\\';
    $class     = $namespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

    if (class_exists($class) && in_array(EvolveModelInterface::class, class_implements($class))) {
        if ($class::shouldEvolve()) {
            $models[] = $class;
        }
    }
}

foreach ($models as $modelClass) {
    $modelSlug       = Str::plural(Str::kebab(class_basename($modelClass)));
    $controllerClass = 'Thinkneverland\\Evolve\\Api\\Http\\Controllers\\EvolveApiController';

    Route::post("/{$modelSlug}", [$controllerClass, 'store'])
        ->name("{$modelSlug}.store")
        ->defaults('modelClass', $modelClass);

    Route::get("/{$modelSlug}", [$controllerClass, 'index'])
        ->name("{$modelSlug}.index")
        ->defaults('modelClass', $modelClass);

    Route::get("/{$modelSlug}/{id}", [$controllerClass, 'show'])
        ->name("{$modelSlug}.show")
        ->defaults('modelClass', $modelClass);

    Route::put("/{$modelSlug}/{id}", [$controllerClass, 'update'])
        ->name("{$modelSlug}.update")
        ->defaults('modelClass', $modelClass);

    Route::delete("/{$modelSlug}/{id}", [$controllerClass, 'destroy'])
        ->name("{$modelSlug}.destroy")
        ->defaults('modelClass', $modelClass);
}
