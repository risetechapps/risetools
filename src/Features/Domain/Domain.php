<?php

namespace RiseTechApps\RiseTools\Features\Domain;

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

    /**
     * Resultados das consultas de rede desta instância: cada DNS/SSL/WHOIS sai
     * uma vez só, mesmo chamando getInfo() e os métodos avulsos.
     */
    protected array $memo = [];

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
        return $this->remember('ip', function () {
            $records = (new Dns())->getRecords($this->getFullHost(), 'A');

            return count($records) > 0 ? $records[0]->ip() : null;
        });
    }

    /**
     * Retorna todos os registros DNS (A, MX, TXT, CNAME).
     */
    public function getDnsRecords(int $type = DNS_ALL): array
    {
        return $this->remember("dns:{$type}", fn() => @dns_get_record($this->getFullHost(), $type) ?: []);
    }

    /**
     * Certificado SSL do host.
     *
     * - status: conseguiu ler o certificado.
     * - is_trusted: a cadeia e o nome conferem (handshake verificado).
     * - is_expired: passou da data de validade.
     *
     * Antes o handshake era sempre verificado, então um certificado expirado
     * ou autoassinado derrubava a conexão e virava só status=false: o
     * is_expired nunca saía true.
     */
    public function getSslInfo(): array
    {
        return $this->remember('ssl', function () {
            try {
                $trusted = true;
                $cert = $this->fetchCertificate(true);

                if ($cert === null) {
                    // Não verificou: tenta de novo sem verificar só para ler o
                    // certificado (expirado, autoassinado, nome errado...).
                    $trusted = false;
                    $cert = $this->fetchCertificate(false);
                }

                if ($cert === null) {
                    return ['status' => false, 'expires_at' => null];
                }

                $validTo = $cert['validTo_time_t'] ?? null;

                return [
                    'status' => true,
                    'issuer' => $cert['issuer']['O'] ?? 'Unknown',
                    'expires_at' => $validTo ? date('Y-m-d H:i:s', $validTo) : null,
                    'is_expired' => $validTo ? now()->timestamp > $validTo : null,
                    'is_trusted' => $trusted,
                ];
            } catch (\Throwable) {
                return ['status' => false, 'expires_at' => null];
            }
        });
    }

    /**
     * Abre a conexão TLS e devolve o certificado parseado, ou null.
     */
    protected function fetchCertificate(bool $verify): ?array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $this->getFullHost(),
        ]]);

        $client = @stream_socket_client(
            "ssl://{$this->getFullHost()}:443",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$client) {
            return null;
        }

        try {
            $params = stream_context_get_params($client);
            $peer = $params['options']['ssl']['peer_certificate'] ?? null;

            if (!$peer) {
                return null;
            }

            $cert = openssl_x509_parse($peer);

            return is_array($cert) ? $cert : null;
        } finally {
            fclose($client);
        }
    }

    /**
     * Verifica a validade do domínio (WHOIS básico via DNS).
     * Nota: Para WHOIS completo, seria necessário uma biblioteca de terceiros.
     */
    public function isResolvable(): bool
    {
        return $this->remember('resolvable', fn() => checkdnsrr($this->getDomain(), "ANY"));
    }

    public function getWhoisExpiration(): ?string
    {
        return $this->remember('whois', function () {
            $whois = Factory::get()->createWhois();

            try {
                $response = $whois->loadDomainInfo($this->getDomain());
                if (!$response || !$response->expirationDate) {
                    return null;
                }
                return date('Y-m-d', $response->expirationDate);
            } catch (ConnectionException|ServerMismatchException|WhoisException) {
                return null;
            }
        });
    }

    /**
     * Verifica se o domínio já está "público" na internet e se
     * o apontamento condiz com o esperado.
     */
    public function isPublished(): bool
    {
        return $this->remember('published', function () {
            // Sem subdomínio é o mesmo host do getIp(): reaproveita a consulta A.
            if ($this->getSubDomain() === null) {
                return $this->getIp() !== null;
            }

            return !empty((new Dns())->getRecords($this->getDomain(), 'A'));
        });
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
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function remember(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }

    public function getUrl(string $protocol = 'http'): string
    {
        $protocol = str_replace(['://', '/'], '', $protocol);

        return "{$protocol}://{$this->getFullHost()}";
    }

    /**
     * Todas as informações do domínio. Faz várias consultas de rede (DNS, SSL,
     * WHOIS): passe $cacheSeconds para guardar o resultado no cache do Laravel
     * e não repetir as consultas a cada chamada.
     */
    public function getInfo(?int $cacheSeconds = null): array
    {
        if ($cacheSeconds !== null && $cacheSeconds > 0) {
            return Cache::remember(
                'risetools:domain:' . $this->getFullHost(),
                $cacheSeconds,
                fn() => $this->buildInfo()
            );
        }

        return $this->buildInfo();
    }

    protected function buildInfo(): array
    {
        $ssl = $this->getSslInfo();

        // fullUrl reflects reality: https only when the cert is readable, trusted and not expired.
        $httpsOk = ($ssl['status'] ?? false) === true
            && ($ssl['is_trusted'] ?? false) === true
            && ($ssl['is_expired'] ?? true) === false;
        $canonicalProtocol = $httpsOk ? 'https' : 'http';

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
