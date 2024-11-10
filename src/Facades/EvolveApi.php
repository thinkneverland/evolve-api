<?php

namespace Thinkneverland\Evolve\Api\Facades;

use Illuminate\Support\Facades\Facade;

class EvolveApi extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'evolve-api';
    }
}
