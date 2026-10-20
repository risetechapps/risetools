<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Tests\TestCase;

class DeviceTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    public function test_forwarded_headers_are_ignored_without_trusted_proxy(): void
    {
        $this->withRequest([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '1.2.3.5',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
        ]);

        $this->assertSame('203.0.113.9', Device::getClientPublicIp());
    }

    public function test_cloudflare_header_only_when_enabled(): void
    {
        $this->withRequest(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8']);
        config(['risetools.ip.trust_cloudflare_header' => true]);

        $this->assertSame('5.6.7.8', Device::getClientPublicIp());
    }

    public function test_invalid_cloudflare_header_falls_back_to_request_ip(): void
    {
        $this->withRequest(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip']);
        config(['risetools.ip.trust_cloudflare_header' => true]);

        $this->assertSame('203.0.113.9', Device::getClientPublicIp());
    }

    public function test_user_agent_comes_from_the_request_not_the_query_string(): void
    {
        $this->withRequest(['HTTP_USER_AGENT' => self::CHROME], '/?agent=Googlebot');

        $info = Device::info(false);

        $this->assertSame('Chrome', $info['browser']);
        $this->assertSame('Desktop', $info['device']);
    }

    public function test_geo_can_be_skipped(): void
    {
        $this->withRequest(['HTTP_USER_AGENT' => self::CHROME]);

        $this->assertArrayNotHasKey('geo_ip', Device::info(false));
        $this->assertArrayHasKey('geo_ip', Device::info());
    }

    public function test_geo_is_disabled_by_default(): void
    {
        $this->useGeoCacheOnDisk();
        config(['risetools.geoip.url' => 'http://127.0.0.1:9/{ip}']);
        $this->withRequest(['REMOTE_ADDR' => '8.8.8.8']);

        $this->assertSame('', Device::info()['geo_ip']['status']);
        // Desligado: nem consulta, nem grava cache negativo.
        $this->assertNull($this->geoCache()->get('risetools:geoip:8.8.8.8'));
    }

    public function test_private_ip_is_not_looked_up(): void
    {
        $this->useGeoCacheOnDisk();
        config(['risetools.geoip.enabled' => true, 'risetools.geoip.url' => 'http://127.0.0.1:9/{ip}']);
        $this->withRequest(['REMOTE_ADDR' => '10.1.2.3']);

        Device::info();

        $this->assertNull($this->geoCache()->get('risetools:geoip:10.1.2.3'));
    }

    public function test_failure_is_cached_so_the_next_request_does_not_wait_again(): void
    {
        $this->useGeoCacheOnDisk();
        // Porta fechada: a "consulta" falha na hora, sem rede externa.
        config([
            'risetools.geoip.enabled' => true,
            'risetools.geoip.url' => 'http://127.0.0.1:9/{ip}',
            'risetools.geoip.connect_timeout' => 1,
        ]);
        $this->withRequest(['REMOTE_ADDR' => '8.8.8.8']);

        $this->assertSame('', Device::info()['geo_ip']['status']);
        $this->assertSame([], $this->geoCache()->get('risetools:geoip:8.8.8.8'));
    }

    /**
     * O Device resolve o store direto da config (resolve()): com o driver
     * array cada resolução seria um store novo e vazio.
     */
    private function useGeoCacheOnDisk(): void
    {
        config([
            'cache.stores.geo_file' => ['driver' => 'file', 'path' => $this->tempPath . '/cache'],
            'risetools.geoip.cache_store' => 'geo_file',
        ]);
    }

    private function geoCache(): \Illuminate\Contracts\Cache\Repository
    {
        return app('cache')->resolve('geo_file');
    }
}
