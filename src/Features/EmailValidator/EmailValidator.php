<?php

namespace RiseTechApps\RiseTools\Features\EmailValidator;

class EmailValidator
{
    /**
     * Lista embutida mínima. A lista completa (milhares de domínios) vem do
     * risetools:email-disposable-update; extras pela config.
     */
    public const BUILT_IN_DISPOSABLE = [
        'tempmail.com', '10minutemail.com', 'guerrillamail.com',
        'mailinator.com', 'yopmail.com', 'throwawaymail.com',
        'tempmailaddress.com', 'burnermail.io', 'temp-mail.org',
        'fakeinbox.com', 'getairmail.com', 'sharklasers.com',
        'mailnesia.com', 'mailcatch.com', 'tempinbox.com',
        'emailondeck.com', 'tempmailo.com', 'disposableemail.org',
        // Domínios brasileiros temporários conhecidos
        'emailtemporario.com.br', 'mohmal.com', 'tempm.com',
    ];

    /** @var array<string, true>|null */
    private ?array $disposableSet = null;

    /** Muda quando o arquivo ou a config mudam: aí a lista é recarregada. */
    private ?string $disposableFingerprint = null;

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

        $dns = $this->dnsLookup($domain);

        // Alguns domínios não têm MX mas têm A record (ex: antigos servidores)
        return !empty($dns['mx']) || $dns['a'];
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
        $domain = strtolower(trim($this->getDomain($email) ?? ''));

        if ($domain === '') {
            return false;
        }

        $set = $this->disposableSet();

        // O domínio e cada pai dele: "a.b.mailinator.com" casa com "mailinator.com".
        $labels = explode('.', $domain);

        while (count($labels) >= 2) {
            if (isset($set[implode('.', $labels)])) {
                return true;
            }

            array_shift($labels);
        }

        return false;
    }

    /**
     * Embutidos + risetools.email.disposable_domains + arquivo baixado.
     *
     * @return array<string, true>
     */
    protected function disposableSet(): array
    {
        $path = (string) config('risetools.email.disposable_list_path', '');
        $extra = (array) config('risetools.email.disposable_domains', []);

        if ($path !== '') {
            // Processo longo (Octane/worker): sem isso o filemtime viria do cache de stat do PHP.
            clearstatcache(true, $path);
        }

        $file = $path !== '' && is_file($path) ? $path : null;
        $fingerprint = ($file ? $file . '|' . filemtime($file) . '|' . filesize($file) : 'no-file') . '|' . md5(json_encode($extra));

        if ($this->disposableSet !== null && $this->disposableFingerprint === $fingerprint) {
            return $this->disposableSet;
        }

        $domains = array_merge(self::BUILT_IN_DISPOSABLE, $extra);

        if ($file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '' && !str_starts_with($line, '#')) {
                    $domains[] = $line;
                }
            }
        }

        $this->disposableFingerprint = $fingerprint;

        return $this->disposableSet = array_fill_keys(array_map('strtolower', $domains), true);
    }

    /**
     * Verifica se o e-mail é genérico/institucional (não pessoal).
     *
     * O termo precisa ser a parte local inteira ou vir seguido de separador ou
     * dígito: "rh@", "rh.sp@", "suporte2@" contam; "rhaissa@" e
     * "informatica@" não.
     */
    public function isRoleBased(string $email): bool
    {
        $roleNames = [
            'contato', 'contact', 'suporte', 'support', 'vendas', 'sales',
            'atendimento', 'help', 'info', 'admin', 'administrador',
            'comercial', 'financeiro', 'rh', 'recursos', 'marketing',
            'noreply', 'no-reply', 'naoresponder', 'webmaster',
            'hostmaster', 'postmaster', 'abuse', 'security',
        ];

        $localPart = strtolower($this->getLocalPart($email) ?? '');

        if ($localPart === '') {
            return false;
        }

        $alternatives = implode('|', array_map(fn($name) => preg_quote($name, '/'), $roleNames));

        return preg_match('/^(?:' . $alternatives . ')(?:$|[._+\-]|\d)/', $localPart) === 1;
    }

    /**
     * Verificação SMTP avançada (tenta conectar no servidor de e-mail).
     *
     * ⚠️ ATENÇÃO: Muitos servidores bloqueiam ou retardam essa verificação
     * para evitar enumeração de usuários, e muitas nuvens bloqueiam a porta 25
     * de saída. Use com cautela.
     */
    public function verifySmtp(string $email, int $timeout = 10, string $from = 'verify@example.com'): array
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

        $connection = @fsockopen($server, 25, $errno, $errstr, $timeout);

        if (!$connection) {
            $result['message'] = "Connection failed: {$errstr}";
            return $result;
        }

        try {
            stream_set_timeout($connection, $timeout);

            // Banner: 220
            [$code, $banner] = $this->smtpRead($connection);
            $result['server'] = $banner;

            if ($code !== 220) {
                $result['smtp_code'] = $code;
                $result['message'] = 'Unexpected banner from server';
                return $result;
            }

            // Cada etapa precisa de 250 antes da próxima: sem isso, a resposta
            // lida no RCPT podia ser a de um comando anterior.
            foreach (['HELO ' . gethostname(), "MAIL FROM: <{$from}>"] as $command) {
                [$code] = $this->smtpCommand($connection, $command);

                if ($code !== 250) {
                    $result['smtp_code'] = $code;
                    $result['message'] = "Server rejected '" . strtok($command, ' ') . "'";
                    return $result;
                }
            }

            // RCPT TO (verifica se a caixa existe)
            [$code] = $this->smtpCommand($connection, "RCPT TO: <{$email}>");
            $result['smtp_code'] = $code;

            // 250/251 = aceito, 550/551/553 = caixa inexistente
            if (in_array($code, [250, 251], true)) {
                $result['verified'] = true;
                $result['message'] = 'Mailbox exists';
            } elseif (in_array($code, [550, 551, 553], true)) {
                $result['message'] = 'Mailbox does not exist';
            } else {
                $result['message'] = 'Uncertain response';
            }
        } catch (\Throwable $e) {
            $result['message'] = 'Exception: ' . $e->getMessage();
        } finally {
            // Finaliza a conversação
            @fwrite($connection, "QUIT\r\n");
            fclose($connection);
        }

        return $result;
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private function smtpCommand($connection, string $command): array
    {
        fwrite($connection, $command . "\r\n");

        return $this->smtpRead($connection);
    }

    /**
     * Lê uma resposta SMTP completa. Respostas multi-linha usam "250-..." em
     * todas as linhas menos a última ("250 ..."), então lê até a linha cujo
     * 4º caractere é espaço.
     *
     * @return array{0: int|null, 1: string}
     */
    private function smtpRead($connection): array
    {
        $lines = [];

        while (($line = fgets($connection, 1024)) !== false) {
            $lines[] = rtrim($line, "\r\n");

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if (stream_get_meta_data($connection)['timed_out'] ?? false) {
            throw new \RuntimeException('SMTP server timed out');
        }

        $last = end($lines) ?: '';
        $code = preg_match('/^(\d{3})/', $last, $matches) ? (int) $matches[1] : null;

        return [$code, implode("\n", $lines)];
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

        return $this->dnsLookup($domain)['mx'];
    }

    /**
     * MX (host => peso, por prioridade) e, sem MX, se há registro A. Cacheado
     * por domínio por risetools.email.dns_cache_minutes: um cadastro em massa
     * do mesmo domínio não repete a consulta DNS para cada e-mail.
     *
     * @return array{mx: array<string, int>, a: bool}
     */
    protected function dnsLookup(string $domain): array
    {
        $domain = strtolower($domain);

        $resolve = function () use ($domain): array {
            $mxRecords = [];
            $weight = [];
            $mx = [];

            if (@getmxrr($domain, $mxRecords, $weight) && !empty($mxRecords)) {
                // Combina MX com peso e ordena por prioridade
                $mx = array_combine($mxRecords, $weight);
                asort($mx);
            }

            return [
                'mx' => $mx,
                'a' => empty($mx) && !empty(@dns_get_record($domain, DNS_A)),
            ];
        };

        $minutes = (int) config('risetools.email.dns_cache_minutes', 60);

        if ($minutes <= 0) {
            return $resolve();
        }

        return $this->dnsCache()->remember("risetools:email:dns:{$domain}", now()->addMinutes($minutes), $resolve);
    }

    /**
     * Store resolvido direto da config, sem prefixo dinâmico: DNS de um
     * domínio é igual para todo mundo.
     */
    protected function dnsCache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('risetools.email.cache_store') ?: config('cache.default');

        try {
            return app('cache')->resolve($store);
        } catch (\Throwable) {
            return \Illuminate\Support\Facades\Cache::store();
        }
    }
}
