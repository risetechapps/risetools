<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Support\Facades\Facade;

/**
 * @see \RiseTechApps\RiseTools\Skeleton\SkeletonClass
 */
class RiseToolsFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'rise-tools';
    }
}
