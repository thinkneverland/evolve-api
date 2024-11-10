<?php

namespace Thinkneverland\Evolve\Api;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class EvolveApiServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerRoutes();
    }

    protected function registerRoutes()
    {
        Route::group([
            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        });
    }
}
