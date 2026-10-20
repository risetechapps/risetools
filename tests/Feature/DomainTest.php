<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use RiseTechApps\RiseTools\Features\Domain\Domain;
use RiseTechApps\RiseTools\Tests\TestCase;

class DomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PSL que vem no pacote: os testes offline não baixam a lista.
        Cache::put('risetools:psl', file_get_contents(__DIR__ . '/../../src/Features/Domain/public_suffix_list.dat'), 3600);
    }

    public function test_registrable_domain_and_subdomain(): void
    {
        $domain = new Domain('https://app.loja.example.com.br/path?x=1');

        $this->assertSame('example.com.br', $domain->getDomain());
        $this->assertSame('app.loja', $domain->getSubDomain());
        $this->assertSame('app.loja.example.com.br', $domain->getFullHost());
    }

    public function test_domain_without_subdomain(): void
    {
        $domain = new Domain('example.com');

        $this->assertSame('example.com', $domain->getDomain());
        $this->assertNull($domain->getSubDomain());
        $this->assertSame('example.com', $domain->getFullHost());
    }

    public function test_url_normalizes_protocol(): void
    {
        $domain = new Domain('www.example.com');

        $this->assertSame('http://www.example.com', $domain->getUrl());
        $this->assertSame('https://www.example.com', $domain->getUrl('https://'));
    }

    public function test_network_calls_are_memoized_per_instance(): void
    {
        $domain = new SpyDomain('example.com');

        $domain->getSslInfo();
        $domain->getSslInfo();
        $domain->getSslInfo();

        $this->assertSame(1, $domain->fetches);
    }

    public function test_get_info_uses_laravel_cache_when_asked(): void
    {
        $domain = new SpyDomain('example.com');
        $domain->getInfoCachedFor(60);
        (new SpyDomain('example.com'))->getInfoCachedFor(60);

        $this->assertTrue(Cache::has('risetools:domain:example.com'));
    }

    #[Group('network')]
    public function test_expired_certificate_is_reported(): void
    {
        $ssl = (new Domain('expired.badssl.com'))->getSslInfo();

        $this->assertTrue($ssl['status']);
        $this->assertTrue($ssl['is_expired']);
        $this->assertFalse($ssl['is_trusted']);
    }

    #[Group('network')]
    public function test_self_signed_certificate_is_not_trusted(): void
    {
        $ssl = (new Domain('self-signed.badssl.com'))->getSslInfo();

        $this->assertTrue($ssl['status']);
        $this->assertFalse($ssl['is_trusted']);
    }

    #[Group('network')]
    public function test_valid_certificate(): void
    {
        $ssl = (new Domain('badssl.com'))->getSslInfo();

        $this->assertTrue($ssl['is_trusted']);
        $this->assertFalse($ssl['is_expired']);
    }

    #[Group('network')]
    public function test_full_url_is_http_when_certificate_is_expired(): void
    {
        $this->assertSame('http://expired.badssl.com', (new Domain('expired.badssl.com'))->getInfo()['fullUrl']);
    }
}

/**
 * Sem rede: conta as conexões TLS e devolve um certificado fixo; o resto do
 * getInfo() vira valor fixo também.
 */
class SpyDomain extends Domain
{
    public int $fetches = 0;

    protected function fetchCertificate(bool $verify): ?array
    {
        $this->fetches++;

        return ['issuer' => ['O' => 'Test CA'], 'validTo_time_t' => time() + 86400];
    }

    public function getIp(): ?string
    {
        return '93.184.216.34';
    }

    public function getDnsRecords(int $type = DNS_ALL): array
    {
        return [];
    }

    public function isResolvable(): bool
    {
        return true;
    }

    public function isPublished(): bool
    {
        return true;
    }

    public function getWhoisExpiration(): ?string
    {
        return null;
    }

    public function getInfoCachedFor(int $seconds): array
    {
        return $this->getInfo($seconds);
    }
}
