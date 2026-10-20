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

    // IP do cliente em Device::getClientPublicIp(): vem de request()->ip(), então
    // atrás de proxy/load balancer configure o TrustProxies do Laravel.
    'ip' => [
        // Lê o header CF-Connecting-IP. Ligue só se a origem aceita tráfego
        // apenas da Cloudflare (firewall/Authenticated Origin Pulls): senão o
        // header é forjável.
        'trust_cloudflare_header' => env('RISETOOLS_TRUST_CLOUDFLARE_HEADER', false),
    ],

    // Geolocalização do IP em Device::info(). É uma chamada HTTP externa e o IP
    // do usuário sai para um terceiro (LGPD): desligada por padrão.
    'geoip' => [
        'enabled' => env('RISETOOLS_GEOIP_ENABLED', false),
        // {ip} é substituído. O plano gratuito do ip-api é só HTTP, uso não
        // comercial e 45 req/min.
        'url' => env('RISETOOLS_GEOIP_URL', 'http://ip-api.com/json/{ip}'),
        'connect_timeout' => env('RISETOOLS_GEOIP_CONNECT_TIMEOUT', 2),
        'timeout' => env('RISETOOLS_GEOIP_TIMEOUT', 4),
        // Falha não é consultada de novo por este tempo (minutos).
        'failure_ttl_minutes' => env('RISETOOLS_GEOIP_FAILURE_TTL', 10),
        // null = store padrão do cache (resolvido direto, sem prefixo dinâmico).
        'cache_store' => env('RISETOOLS_GEOIP_CACHE_STORE'),
    ],

    // risetools:doctor e rotas de health.
    'health' => [
        // Store do heartbeat do scheduler (null = store padrão, sem prefixo).
        'cache_store' => env('RISETOOLS_HEALTH_CACHE_STORE'),

        'scheduler' => [
            // Agenda um heartbeat a cada minuto; o check "scheduler" confere a idade.
            'heartbeat' => env('RISETOOLS_SCHEDULER_HEARTBEAT', true),
            'max_age_minutes' => env('RISETOOLS_SCHEDULER_MAX_AGE', 5),
        ],

        // Checks próprios: 'nome' => Classe::class (invocável; recebe array $options,
        // devolve string de detalhe e lança exceção para falhar).
        'checks' => [],

        // GET {path}/live e {path}/ready, para orquestrador/load balancer.
        'route' => [
            'enabled' => env('RISETOOLS_HEALTH_ROUTE', false),
            'path' => env('RISETOOLS_HEALTH_PATH', 'health'),
            'middleware' => [],
            // Checks do /ready (rápidos: rodam a cada probe).
            'checks' => ['database', 'cache'],
            // Com token (header X-Health-Token ou Bearer) ou IP na lista, o /ready
            // mostra o detalhe de cada check; sem nenhum dos dois configurado,
            // responde só ok/falhou.
            'token' => env('RISETOOLS_HEALTH_TOKEN'),
            'allowed_ips' => array_filter(explode(',', (string) env('RISETOOLS_HEALTH_ALLOWED_IPS', ''))),
        ],
    ],

    'email' => [
        // Cache dos lookups de MX/A por domínio (minutos; 0 desliga).
        'dns_cache_minutes' => env('RISETOOLS_EMAIL_DNS_CACHE', 60),
        'cache_store' => env('RISETOOLS_EMAIL_CACHE_STORE'),

        // Domínios descartáveis extras, além da lista embutida.
        'disposable_domains' => [],

        // Lista baixada por risetools:email-disposable-update (um domínio por linha).
        'disposable_list_path' => env('RISETOOLS_DISPOSABLE_LIST_PATH', storage_path('app/risetools/disposable_domains.txt')),
        'disposable_list_url' => env('RISETOOLS_DISPOSABLE_LIST_URL', 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf'),
    ],
];
