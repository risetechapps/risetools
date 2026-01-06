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

    public function __construct(string $domain)
    {

        $domain = parse_url($domain, PHP_URL_HOST) ?? $domain;

        $this->rules = Rules::fromPath('https://publicsuffix.org/list/public_suffix_list.dat');

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
            'fullUrl' => $this->getUrl()
        ];
    }

    public function isValidMail(string $email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (!$this->isResolvable()) {
            return false;
        }

        if (!checkdnsrr($this->getDomain(), 'MX')) {
            return false;
        }

        $blockedProviders = [
            'gmail.com',
            'googlemail.com',
            'outlook.com',
            'hotmail.com',
            'live.com',
            'yahoo.com',
            'icloud.com',
        ];

        if (in_array($this->getDomain(), $blockedProviders, true)) {
            return true;
        }

        return $this->smtpRcptCheck($email);
    }

    protected function smtpRcptCheck(string $email): bool
    {
        [$user, $domain] = explode('@', $email, 2);

        getmxrr($domain, $mxHosts);
        if (empty($mxHosts)) {
            return false;
        }

        $mx = $mxHosts[0];

        $fp = @fsockopen($mx, 25, $errno, $errstr, 10);
        if (!$fp) {
            return false;
        }

        stream_set_timeout($fp, 10);

        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 1024)) {
                $data .= $line;
                if (preg_match('/^\d{3} /', $line)) {
                    break;
                }
            }
            return trim($data);
        };

        $send = fn ($cmd) => fputs($fp, $cmd . "\r\n");

        $read();

        $send('EHLO risetech.com.br');
        $read();

        $send('MAIL FROM:<apps@risetech.com.br>');
        $mailFromResponse = $read();

        if (!str_starts_with($mailFromResponse, '250')) {
            fclose($fp);
            return false;
        }

        $send("RCPT TO:<{$email}>");
        $rcptResponse = $read();

        $send('QUIT');
        fclose($fp);

        if (str_starts_with($rcptResponse, '250')) {
            return true;
        }

        if (
            str_contains($rcptResponse, '5.1.1') ||
            str_contains($rcptResponse, 'User unknown') ||
            str_contains($rcptResponse, '5.5.1')
        ) {
            return false;
        }

        return true;
    }


}
