<?php

namespace Hassam\DataAccessWrapper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hassam\DataAccessWrapper\DataAccessWrapper
 */
class DataAccessWrapper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'data-access-wrapper';
    }
}
