<?php

namespace RiseTechApps\RiseTools\Features\Domain;

use Exception;
use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Factory;
use Illuminate\Support\Facades\Cache;
use Pdp\Domain as PdpDomain;
use Pdp\ResolvedDomainName;
use Pdp\Rules;
use Spatie\Dns\Dns;

class Domain
{
    /**
     * Cache key + TTL for the downloaded Public Suffix List.
     */
    protected const PSL_CACHE_KEY = 'risetools:psl';
    protected const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    /**
     * Process-level memo of the parsed rules. The PSL is global immutable data,
     * so a static cache is safe (unlike per-request user data) and avoids
     * re-parsing the ~280KB list on every instantiation.
     */
    protected static ?Rules $rulesCache = null;

    protected Rules $rules;
    protected ResolvedDomainName $resolvedDomainName;

    public function __construct(string $domain)
    {

        $domain = parse_url($domain, PHP_URL_HOST) ?? $domain;

        $this->rules = static::resolveRules();

        $domain = PdpDomain::fromIDNA2008($domain);
        $this->resolvedDomainName = $this->rules->resolve($domain);
    }

    /**
     * Resolve the PSL rules without hitting the network on the request path.
     *
     * Order: process memo → Laravel cache (7 days) → one remote download →
     * bundled fallback copy. Never downloads on every instantiation and never
     * hard-fails when publicsuffix.org is unreachable.
     */
    protected static function resolveRules(): Rules
    {
        if (static::$rulesCache instanceof Rules) {
            return static::$rulesCache;
        }

        $content = Cache::get(self::PSL_CACHE_KEY);

        if (blank($content)) {
            $content = static::downloadPsl();

            if (filled($content)) {
                Cache::put(self::PSL_CACHE_KEY, $content, now()->addDays(7));
            } else {
                // Offline / download failed → bundled copy (not cached, so a
                // later request can still refresh from the network).
                $bundled = __DIR__ . '/public_suffix_list.dat';
                $content = is_file($bundled) ? file_get_contents($bundled) : false;
            }
        }

        if (blank($content)) {
            throw new \RuntimeException(
                'Unable to load the Public Suffix List: remote download failed and no bundled copy was found at ' . __DIR__ . '/public_suffix_list.dat'
            );
        }

        return static::$rulesCache = Rules::fromString($content);
    }

    /**
     * Download the PSL once with a short timeout. Returns null on any failure.
     */
    protected static function downloadPsl(): ?string
    {
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 5],
                'https' => ['timeout' => 5],
            ]);

            $data = @file_get_contents(self::PSL_URL, false, $context);

            return ($data !== false && trim($data) !== '') ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDomain(): string
    {
        return $this->resolvedDomainName->registrableDomain()->toString();
    }

    public function getSubDomain(): string|null
    {
        return filled($this->resolvedDomainName->subDomain()->toString())
            ? $this->resolvedDomainName->subDomain()->toString() : null;
    }

    public function getIp(): ?string
    {
        $dns = new Dns();

        $domain = is_null($this->getSubDomain()) ? $this->getDomain() : $this->getSubDomain() . "." . $this->getDomain();

        $records = $dns->getRecords($domain, 'A');

        if (count($records) > 0) {
            return $records[0]->ip();
        }
        return null;
    }

    /**
     * Retorna todos os registros DNS (A, MX, TXT, CNAME).
     */
    public function getDnsRecords(int $type = DNS_ALL): array
    {
        $domain = is_null($this->getSubDomain()) ? $this->getDomain() : $this->getSubDomain() . "." . $this->getDomain();

        return dns_get_record($domain, $type) ?: [];
    }

    /**
     * Verifica se o domínio possui um SSL válido e retorna a data de expiração.
     */
    public function getSslInfo(): array
    {
        try {

            $domain = is_null($this->getSubDomain()) ? $this->getDomain() : $this->getSubDomain() . "." . $this->getDomain();

            $context = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
            $client = @stream_socket_client(
                "ssl://{$domain}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$client) return ['status' => false, 'expires_at' => null];

            $params = stream_context_get_params($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            return [
                'status' => true,
                'issuer' => $cert['issuer']['O'] ?? 'Unknown',
                'expires_at' => date('Y-m-d H:i:s', $cert['validTo_time_t']),
                'is_expired' => now()->timestamp > $cert['validTo_time_t']
            ];
        } catch (Exception) {
            return ['status' => false];
        }
    }

    /**
     * Verifica a validade do domínio (WHOIS básico via DNS).
     * Nota: Para WHOIS completo, seria necessário uma biblioteca de terceiros.
     */
    public function isResolvable(): bool
    {
        return checkdnsrr($this->getDomain(), "ANY");
    }

    public function getWhoisExpiration(): ?string
    {
        $whois = Factory::get()->createWhois();

        try {
            $response = $whois->loadDomainInfo($this->getDomain());
            if (!$response) {
                return null;
            }
            return date('Y-m-d', $response->expirationDate);
        } catch (ConnectionException|ServerMismatchException|WhoisException) {
            return null;
        }
    }

    /**
     * Verifica se o domínio já está "público" na internet e se
     * o apontamento condiz com o esperado.
     */
    public function isPublished(): bool
    {
        $dns = new \Spatie\Dns\Dns();
        $records = $dns->getRecords($this->getDomain(), 'A');

        if (empty($records)) {
            return false;
        }
        return true;
    }

    /**
     * Retorna o host completo (subdomínio + domínio) como string.
     * Exemplo: sub.google.com ou google.com
     */
    public function getFullHost(): string
    {
        return is_null($this->getSubDomain())
            ? $this->getDomain()
            : $this->getSubDomain() . "." . $this->getDomain();
    }

    /**
     * Retorna a URL completa com protocolo.
     * @param string $protocol (http ou https)
     */
    public function getUrl(string $protocol = 'http'): string
    {
        $protocol = str_replace(['://', '/'], '', $protocol);

        return "{$protocol}://{$this->getFullHost()}";
    }

    public function getInfo(): array
    {
        // Open the SSL socket once and reuse it for both the 'ssl' payload and
        // the canonical URL decision below.
        $ssl = $this->getSslInfo();

        // fullUrl reflects reality: https only when the host has a valid cert.
        $canonicalProtocol = ($ssl['status'] ?? false) === true ? 'https' : 'http';

        return [
            'domain' => $this->getDomain(),
            'hasSubDomain' => !($this->getSubDomain() === null),
            'subDomain' => $this->getSubDomain(),
            'ip' => $this->getIp(),
            'dns' => $this->getDnsRecords(),
            'ssl' => $ssl,
            'resolve' => $this->isResolvable(),
            'status' => $this->isPublished(),
            'expires_at' => $this->getWhoisExpiration(),
            'url' => $this->getUrl(),
            'fullUrl' => $this->getUrl($canonicalProtocol),
        ];
    }

}
