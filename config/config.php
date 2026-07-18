<?php

/*
 * You can place your custom package configuration in here.
 */
return [

    'theme' => [
        'errors' => env('ERROR_THEME', 'default'),
        'emails' => env('EMAIL_THEME', 'default'),
    ],

    'snapshot' => [
        'disk' => env('RISETOOLS_SNAPSHOT_DISK', 'local'),
        'path' => env('RISETOOLS_SNAPSHOT_PATH', 'snapshots'),
    ],
];
