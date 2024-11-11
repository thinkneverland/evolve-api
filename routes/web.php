<?php

use Illuminate\Support\Facades\Route;
use Thinkneverland\Evolve\Api\Http\Controllers\DocsController;

// Route to display Swagger UI
Route::get('/docs', [DocsController::class, 'show'])->name('evolve-api.docs');

// Route to serve the generated Swagger JSON
Route::get('/docs.json', [DocsController::class, 'json'])->name('evolve-api.docs.json');
