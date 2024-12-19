<?php

use Illuminate\Support\Facades\Route;
use Thinkneverland\Evolve\Api\Http\Controllers\{EvolveApiController, DocsController};

// Documentation routes
if (config('evolve-api.docs.enabled')) {
    Route::get('docs', [DocsController::class, 'show'])
        ->name('evolve-api.docs')
        ->middleware(config('evolve-api.docs.middleware', []));

    Route::get('docs.json', [DocsController::class, 'json'])
        ->name('evolve-api.docs.json')
        ->middleware(config('evolve-api.docs.middleware', []));
}