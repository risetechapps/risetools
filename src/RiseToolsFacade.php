<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator avatar()
 * @method static \RiseTechApps\RiseTools\Features\Domain\Domain domain(string $domain)
 * @method static \RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator email()
 * @method static array device(bool $withGeoIp = true)
 * @method static string|null clientIp()
 * @method static \RiseTechApps\RiseTools\Features\MaskInput\MaskInput mask()
 * @method static \RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot snapshot()
 * @method static \RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor dbHealth()
 * @method static \RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector nPlusOne()
 * @method static \RiseTechApps\RiseTools\Features\HealthCheck\Doctor doctor()
 *
 * @see \RiseTechApps\RiseTools\RiseTools
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
