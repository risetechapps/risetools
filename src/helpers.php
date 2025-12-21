<?php

declare(strict_types=1);

if (!function_exists('avatarGenerator')) {
    function avatarGenerator(): \RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator
    {
        return app(\RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator::class);
    }
}

if (!function_exists('MaskInput')) {
    function MaskInput(string $value, string $mask): string
    {
        return (new \RiseTechApps\RiseTools\Features\MaskInput\MaskInput)->MaskInput($value, $mask);
    }
}

if (!function_exists("domainTools")) {

    function domainTools(string $domain): \RiseTechApps\RiseTools\Features\Domain\Domain
    {
        return new RiseTechApps\RiseTools\Features\Domain\Domain($domain);
    }
}
