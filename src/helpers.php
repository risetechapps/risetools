<?php

declare(strict_types=1);

if (!function_exists('avatarGenerator')) {
    function avatarGenerator(): \RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator
    {
        return app(\RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator::class);
    }
}
