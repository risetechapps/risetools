<?php

namespace RiseTechApps\RiseTools\Features\Device;

use GuzzleHttp\Client;

class Device
{
    public static function info(): array
    {
        try {
            $class = (new \hisorange\BrowserDetect\Parser())
                ->parse($_GET['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Missing');
            return [
                'device' => static::getTypeDevice($class),
                'browser' => static::getTypeBrowser($class),
                'browser_name' => static::getTypeBrowserName($class),
                'platformName' => static::getPlatformName($class),
                'geo_ip' => static::getGeoIP($class)
            ];
        } catch (\Exception $e) {
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

            $client = new Client();

            try {
                $response = $client->get("http://ip-api.com/json/{$ip}");
                if ($response->getStatusCode() == 200) {
                    $responseData = json_decode($response->getBody(), true);
                }
            } catch (\Exception $exception) {

            }

            return $responseData;
        } catch (\Exception $exception) {
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
