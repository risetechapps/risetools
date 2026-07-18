<?php

namespace RiseTechApps\RiseTools\Features\EmailValidator;

use Exception;

class EmailValidator
{
    /**
     * Valida o formato do e-mail (RFC 5322).
     */
    public function isValidFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Verifica se o domínio do e-mail possui registros MX válidos.
     * Indica que o domínio aceita receber e-mails.
     */
    public function hasValidMxRecords(string $email): bool
    {
        if (!$this->isValidFormat($email)) {
            return false;
        }

        $domain = $this->getDomain($email);

        if (!$domain) {
            return false;
        }

        // Verifica registros MX
        $mxRecords = [];
        $weight = [];

        if (!getmxrr($domain, $mxRecords, $weight)) {
            // Alguns domínios não têm MX mas têm A record (ex: antigos servidores)
            $aRecords = dns_get_record($domain, DNS_A);
            return !empty($aRecords);
        }

        return !empty($mxRecords);
    }

    /**
     * Verificação completa: formato + MX records.
     * Recomendado para formulários de cadastro.
     */
    public function isValid(string $email): bool
    {
        return $this->isValidFormat($email) && $this->hasValidMxRecords($email);
    }

    /**
     * Retorna informações detalhadas sobre o e-mail.
     */
    public function getInfo(string $email): array
    {
        $info = [
            'email' => $email,
            'valid_format' => false,
            'domain' => null,
            'has_mx_records' => false,
            'mx_records' => [],
            'is_disposable' => false,
            'is_role_based' => false,
        ];

        if (!$this->isValidFormat($email)) {
            return $info;
        }

        $info['valid_format'] = true;
        $info['domain'] = $this->getDomain($email);
        $info['has_mx_records'] = $this->hasValidMxRecords($email);
        $info['mx_records'] = $this->getMxRecords($email);
        $info['is_disposable'] = $this->isDisposable($email);
        $info['is_role_based'] = $this->isRoleBased($email);

        return $info;
    }

    /**
     * Verifica se o e-mail é de domínio descartável (temporário).
     */
    public function isDisposable(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'mailinator.com', 'yopmail.com', 'throwawaymail.com',
            'tempmailaddress.com', 'burnermail.io', 'temp-mail.org',
            'fakeinbox.com', 'getairmail.com', 'sharklasers.com',
            'mailnesia.com', 'mailcatch.com', 'tempinbox.com',
            'emailondeck.com', 'tempmailo.com', 'disposableemail.org',
            // Domínios brasileiros temporários conhecidos
            'emailtemporario.com.br', 'mohmal.com', 'tempm.com',
        ];

        $domain = strtolower($this->getDomain($email) ?? '');

        foreach ($disposableDomains as $disposable) {
            if ($domain === $disposable || str_ends_with($domain, '.' . $disposable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se o e-mail é genérico/institucional (não pessoal).
     */
    public function isRoleBased(string $email): bool
    {
        $rolePrefixes = [
            'contato', 'contact', 'suporte', 'support', 'vendas', 'sales',
            'atendimento', 'help', 'info', 'admin', 'administrador',
            'comercial', 'financeiro', 'rh', 'recursos', 'marketing',
            'noreply', 'no-reply', 'naoresponder', 'webmaster',
            'hostmaster', 'postmaster', 'abuse', 'security',
        ];

        $localPart = strtolower($this->getLocalPart($email) ?? '');

        foreach ($rolePrefixes as $prefix) {
            if (str_starts_with($localPart, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificação SMTP avançada (tenta conectar no servidor de e-mail).
     *
     * ⚠️ ATENÇÃO: Muitos servidores bloqueiam ou retardam essa verificação
     * para evitar enumeração de usuários. Use com cautela.
     */
    public function verifySmtp(string $email, int $timeout = 10): array
    {
        $result = [
            'email' => $email,
            'verified' => false,
            'smtp_code' => null,
            'message' => null,
            'server' => null,
        ];

        if (!$this->isValidFormat($email) || !$this->hasValidMxRecords($email)) {
            $result['message'] = 'Invalid format or no MX records';
            return $result;
        }

        $mxRecords = $this->getMxRecords($email);

        if (empty($mxRecords)) {
            $result['message'] = 'No MX records found';
            return $result;
        }

        // Tenta o servidor MX com maior prioridade (menor weight)
        $server = array_key_first($mxRecords);

        try {
            $connection = @fsockopen($server, 25, $errno, $errstr, $timeout);

            if (!$connection) {
                $result['message'] = "Connection failed: {$errstr}";
                return $result;
            }

            // Lê banner do servidor
            $response = fgets($connection, 1024);
            $result['server'] = trim($response);

            // En comando HELO
            fputs($connection, "HELO " . gethostname() . "\r\n");
            fgets($connection, 1024);

            // Envelope FROM
            fputs($connection, "MAIL FROM: <verify@example.com>\r\n");
            fgets($connection, 1024);

            // RCPT TO (verifica se a caixa existe)
            fputs($connection, "RCPT TO: <{$email}>\r\n");
            $response = fgets($connection, 1024);

            // Extrai código de resposta
            if (preg_match('/^(\d{3})/', $response, $matches)) {
                $result['smtp_code'] = (int) $matches[1];

                // 250 = Success, 550 = User unknown
                if ($result['smtp_code'] === 250) {
                    $result['verified'] = true;
                    $result['message'] = 'Mailbox exists';
                } elseif ($result['smtp_code'] === 550) {
                    $result['message'] = 'Mailbox does not exist';
                } else {
                    $result['message'] = 'Uncertain response';
                }
            }

            // Finaliza a conversação
            fputs($connection, "QUIT\r\n");
            fclose($connection);

        } catch (Exception $e) {
            $result['message'] = 'Exception: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Extrai o domínio do e-mail.
     */
    public function getDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? null;
    }

    /**
     * Extrai a parte local (antes do @) do e-mail.
     */
    public function getLocalPart(string $email): ?string
    {
        $parts = explode('@', $email);
        return $parts[0] ?? null;
    }

    /**
     * Retorna todos os registros MX do domínio.
     */
    private function getMxRecords(string $email): array
    {
        $domain = $this->getDomain($email);

        if (!$domain) {
            return [];
        }

        $mxRecords = [];
        $weight = [];

        if (getmxrr($domain, $mxRecords, $weight)) {
            // Combina MX com peso e ordena por prioridade
            $combined = array_combine($mxRecords, $weight);
            asort($combined);
            return $combined;
        }

        return [];
    }
}
