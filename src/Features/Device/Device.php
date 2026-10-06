<?php

namespace RiseTechApps\RiseTools\Features\Device;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class Device
{
    /**
     * Dispositivo do request (user agent) e, opcionalmente, a geolocalização do IP.
     *
     * A geolocalização é uma chamada HTTP externa: quem chama no caminho do
     * request (ex.: monitoramento de cada requisição) deve passar `false` e só
     * pedir o geo onde ele vale a espera (ex.: aviso de novo login).
     */
    public static function info(bool $withGeoIp = true): array
    {
        try {
            $class = new \hisorange\BrowserDetect\Parser()
                ->parse($_GET['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Missing');

            $info = [
                'device' => static::getTypeDevice($class),
                'browser' => static::getTypeBrowser($class),
                'browser_name' => static::getTypeBrowserName($class),
                'platformName' => static::getPlatformName($class),
            ];

            if ($withGeoIp) {
                $info['geo_ip'] = static::getGeoIP($class);
            }

            return $info;

        } catch (\Throwable) {
            return [];
        }
    }

    private static function getTypeDevice(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isDesktop()) {
            return 'Desktop';
        } else if ($class->isMobile()) {
            return 'Mobile' . self::getMobileDevice($class);
        } else if ($class->isTablet()) {
            return 'Tablet';
        } else if ($class->isBot()) {
            return 'Bot';
        }
        return 'Unknown';
    }

    private static function getMobileDevice(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isAndroid()) {
            return ' - Android';
        } else if ($class->isMac()) {
            return ' - Mac';
        } else if ($class->isLinux()) {
            return ' - linux';
        } else if ($class->isWindows()) {
            return ' - Windows';
        }

        return '';
    }

    private static function getTypeBrowser(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isChrome()) {
            return 'Chrome';
        } else if ($class->isSafari()) {
            return 'Safari';
        } else if ($class->isOpera()) {
            return 'Opera';
        } else if ($class->isFirefox()) {
            return 'Firefox';
        } else if ($class->isIE()) {
            return 'IE';
        } else if ($class->isEdge()) {
            return 'Edge';
        } else if ($class->isInApp()) {
            return 'webView';
        } else if ($class->isAndroid()) {
            return $class->browserFamily();
        }
        return 'Unknown';
    }

    private static function getTypeBrowserName(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        return $class->browserName();
    }

    private static function getPlatformName(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        return $class->platformName();
    }

    private static function getGeoIP(\hisorange\BrowserDetect\Contracts\ResultInterface $class)
    {
        $responseData = [
            "status" => "",
            "country" => "",
            "countryCode" => "",
            "region" => "",
            "regionName" => "",
            "city" => "",
            "zip" => "",
            "lat" => "",
            "lon" => "",
            "timezone" => "",
            "isp" => "",
            "org" => "",
            "as" => "",
            "query" => "",
        ];

        try {
            if (!config('risetools.geoip.enabled', true)) {
                return $responseData;
            }

            $ip = self::getClientPublicIp();

            // IP privado/reservado (rede interna, localhost, dev) não tem geo:
            // consultar só gastava a chamada e voltava "fail" — em todo request.
            if (blank($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $responseData;
            }

            $cache = static::geoCache();
            $key = "risetools:geoip:{$ip}";
            $cached = $cache->get($key);

            if (is_array($cached)) {
                // [] = falha recente (cache negativo): não consulta de novo agora.
                return $cached === [] ? $responseData : $cached;
            }

            // Timeout curto: nunca prender o worker esperando o provedor.
            $client = new Client([
                'connect_timeout' => (float) config('risetools.geoip.connect_timeout', 2),
                'timeout' => (float) config('risetools.geoip.timeout', 4),
            ]);

            try {
                $url = str_replace('{ip}', rawurlencode($ip), (string) config('risetools.geoip.url', 'http://ip-api.com/json/{ip}'));
                $response = $client->get($url);

                if ($response->getStatusCode() === 200) {
                    $decoded = json_decode((string)$response->getBody(), true);

                    // ip-api retorna status "success" | "fail".
                    if (is_array($decoded) && ($decoded['status'] ?? null) === 'success') {
                        $data = array_merge($responseData, $decoded);
                        $cache->put($key, $data, now()->addHours(24));
                        return $data;
                    }
                }
            } catch (\Throwable) {
                // segue para o cache negativo
            }

            // Falha (provedor fora, limite de requisições, IP sem dados): guarda
            // por poucos minutos. Sem isso, com o provedor fora ou limitando, TODO
            // request pagava o timeout de novo.
            $cache->put($key, [], now()->addMinutes((int) config('risetools.geoip.failure_ttl_minutes', 10)));

            return $responseData;
        } catch (\Throwable) {
            return $responseData;
        }
    }

    /**
     * Store de cache para o geo, FORA do prefixo de tenant.
     *
     * Geo de um IP é igual para todo mundo. Pelo Cache facade, num app com
     * tenancy, a chave ganhava o prefixo do tenant/filial/usuário corrente e o
     * cache quase nunca era reaproveitado. `resolve()` monta o store direto da
     * config, sem passar pelo `store()` que aplica esse prefixo.
     */
    protected static function geoCache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('risetools.geoip.cache_store') ?: config('cache.default');

        try {
            return app('cache')->resolve($store);
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    public static function getClientPublicIp(): ?string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        $headersToCheck = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headersToCheck as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                foreach ($ipList as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return request()->ip();
    }
}
