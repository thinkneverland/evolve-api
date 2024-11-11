<?php

namespace Thinkneverland\Evolve\Api\Http\Controllers;

use Illuminate\Routing\Controller;

class DocsController extends Controller
{
    public function show()
    {
        return view('evolve-api::docs');
    }

    public function json()
    {
        $swaggerPath = public_path('evolve-api-docs.json');

        if (!file_exists($swaggerPath)) {
            abort(404, 'Swagger documentation not found. Please run "php artisan evolve-api:generate-docs" to generate it.');
        }

        return response()->file($swaggerPath, ['Content-Type' => 'application/json']);
    }
}
