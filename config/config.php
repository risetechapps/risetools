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

    // Geolocalização do IP em Device::info(). É uma chamada HTTP externa e o IP
    // do usuário sai para um terceiro (LGPD): desligue se não usar.
    'geoip' => [
        'enabled' => env('RISETOOLS_GEOIP_ENABLED', true),
        // {ip} é substituído. O plano gratuito do ip-api é só HTTP, uso não
        // comercial e 45 req/min.
        'url' => env('RISETOOLS_GEOIP_URL', 'http://ip-api.com/json/{ip}'),
        'connect_timeout' => env('RISETOOLS_GEOIP_CONNECT_TIMEOUT', 2),
        'timeout' => env('RISETOOLS_GEOIP_TIMEOUT', 4),
        // Falha não é consultada de novo por este tempo (minutos).
        'failure_ttl_minutes' => env('RISETOOLS_GEOIP_FAILURE_TTL', 10),
        // null = store padrão do cache (sempre sem prefixo de tenant).
        'cache_store' => env('RISETOOLS_GEOIP_CACHE_STORE'),
    ],
];
