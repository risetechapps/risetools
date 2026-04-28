<?php

namespace RiseTechApps\RiseTools\Features\Domain;

use Exception;
use Iodev\Whois\Exceptions\ConnectionException;
use Iodev\Whois\Exceptions\ServerMismatchException;
use Iodev\Whois\Exceptions\WhoisException;
use Iodev\Whois\Factory;
use Pdp\Domain as PdpDomain;
use Pdp\ResolvedDomainName;
use Pdp\Rules;
use Spatie\Dns\Dns;

class Domain
{
    protected Rules $rules;
    protected ResolvedDomainName $resolvedDomainName;

    private static ?Rules $cachedRules = null;

    public function __construct(string $domain)
    {
        $domain = parse_url($domain, PHP_URL_HOST) ?? $domain;

        if (self::$cachedRules === null) {
            self::$cachedRules = Rules::fromPath('https://publicsuffix.org/list/public_suffix_list.dat');
        }

        $this->rules = self::$cachedRules;

        $domain = PdpDomain::fromIDNA2008($domain);
        $this->resolvedDomainName = $this->rules->resolve($domain);
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
        $records = $dns->getRecords($this->getFullHost(), 'A');

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
        return dns_get_record($this->getFullHost(), $type) ?: [];
    }

    /**
     * Verifica se o domínio possui um SSL válido e retorna a data de expiração.
     */
    public function getSslInfo(): array
    {
        try {
            $context = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
            $client = @stream_socket_client(
                "ssl://{$this->getFullHost()}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$client) {
                return ['status' => false, 'expires_at' => null];
            }

            $params = stream_context_get_params($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            fclose($client);

            return [
                'status' => true,
                'issuer' => $cert['issuer']['O'] ?? 'Unknown',
                'expires_at' => date('Y-m-d H:i:s', $cert['validTo_time_t']),
                'is_expired' => now()->timestamp > $cert['validTo_time_t']
            ];
        } catch (Exception $e) {
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
        } catch (ConnectionException|ServerMismatchException|WhoisException $e) {
            return null;
        }
    }

    /**
     * Verifica se o domínio responde HTTP 200 (está publicado na internet).
     */
    public function isPublished(): bool
    {
        try {
            $headers = @get_headers($this->getUrl('https'), 1);
            if ($headers === false) {
                $headers = @get_headers($this->getUrl('http'), 1);
            }
            return $headers !== false && str_starts_with($headers[0], 'HTTP/');
        } catch (Exception $e) {
            return false;
        }
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
        return [
            'domain' => $this->getDomain(),
            'hasSubDomain' => !($this->getSubDomain() === null),
            'subDomain' => $this->getSubDomain(),
            'ip' => $this->getIp(),
            'dns' => $this->getDnsRecords(),
            'ssl' => $this->getSslInfo(),
            'resolve' => $this->isResolvable(),
            'status' => $this->isPublished(),
            'expires_at' => $this->getWhoisExpiration(),
            'url' => $this->getUrl(),
        ];
    }

}
