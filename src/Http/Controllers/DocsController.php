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
        $path = storage_path('app/evolve-api-docs.json');

        if (!file_exists($path)) {
            abort(404, 'API documentation not generated. Run "php artisan evolve-api:generate-docs" first.');
        }

        return response()->file($path, ['Content-Type' => 'application/json']);
    }
}
