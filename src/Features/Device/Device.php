<?php

namespace RiseTechApps\RiseTools\Features\Device;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class Device
{
    public static function info(): array
    {
        try {
            $class = new \hisorange\BrowserDetect\Parser()
                ->parse($_GET['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Missing');

            return [
                'device' => static::getTypeDevice($class),
                'browser' => static::getTypeBrowser($class),
                'browser_name' => static::getTypeBrowserName($class),
                'platformName' => static::getPlatformName($class),
                'geo_ip' => static::getGeoIP($class)
            ];

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
            $ip = self::getClientPublicIp();

            if (blank($ip)) {
                return $responseData;
            }

            // Geo de um IP é estável: cacheia por IP (compartilhado entre requests
            // e usuários). Só cacheia SUCESSO — falha transitória não fica presa 24h.
            $key = "risetools:geoip:{$ip}";
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            // Timeout curto: nunca prender o worker esperando o ip-api.
            $client = new Client([
                'connect_timeout' => 2,
                'timeout' => 4,
            ]);

            try {
                $response = $client->get("http://ip-api.com/json/{$ip}");

                if ($response->getStatusCode() === 200) {
                    $decoded = json_decode((string)$response->getBody(), true);

                    // ip-api retorna status "success" | "fail"; só cacheia hit real.
                    if (is_array($decoded) && ($decoded['status'] ?? null) === 'success') {
                        $data = array_merge($responseData, $decoded);
                        Cache::put($key, $data, now()->addHours(24));
                        return $data;
                    }
                }
            } catch (\Throwable) {
                // cai no retorno padrão — sem cachear a falha
            }

            return $responseData;
        } catch (\Throwable) {
            return $responseData;
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
