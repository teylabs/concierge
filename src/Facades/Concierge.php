<?php

namespace Tey\Concierge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tey\Concierge\Concierge
 */
class Concierge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tey\Concierge\Concierge::class;
    }
}
