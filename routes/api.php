<?php

use Illuminate\Support\Facades\Route;
use Thinkneverland\Evolve\Api\Http\Controllers\{EvolveApiController, DocsController};

Route::prefix(config('evolve-api.route_prefix'))->group(function () {
    // Documentation routes
    if (config('evolve-api.docs.enabled')) {
        Route::get('docs', [DocsController::class, 'show'])
            ->name('evolve-api.docs')
            ->middleware(config('evolve-api.docs.middleware'));

        Route::get('docs.json', [DocsController::class, 'json'])
            ->name('evolve-api.docs.json')
            ->middleware(config('evolve-api.docs.middleware'));
    }

    // API routes
    Route::group(['middleware' => ['api']], function () {
        Route::get('{modelClass}', [EvolveApiController::class, 'index']);
        Route::post('{modelClass}', [EvolveApiController::class, 'store']);
        Route::get('{modelClass}/{id}', [EvolveApiController::class, 'show']);
        Route::put('{modelClass}/{id}', [EvolveApiController::class, 'update']);
        Route::delete('{modelClass}/{id}', [EvolveApiController::class, 'destroy']);
    })->where(['modelClass' => '[a-zA-Z0-9-_]+', 'id' => '[0-9]+']);
});
