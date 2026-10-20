<?php

namespace RiseTechApps\RiseTools\Features\EmailValidator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DisposableUpdateCommand extends Command
{
    protected $signature = 'risetools:email-disposable-update
                            {--url= : List URL, one domain per line (defaults to risetools.email.disposable_list_url)}
                            {--min=100 : Minimum number of domains for the download to be accepted}';

    protected $description = 'Downloads the list of disposable e-mail domains used by EmailValidator::isDisposable()';

    public function handle(): int
    {
        $url = $this->option('url') ?: config('risetools.email.disposable_list_url');
        $path = (string) config('risetools.email.disposable_list_path');

        if (blank($url) || $path === '') {
            $this->error('Set risetools.email.disposable_list_url and risetools.email.disposable_list_path.');
            return self::FAILURE;
        }

        $this->info("Downloading {$url}...");

        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable $e) {
            $this->error("Download failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        if (!$response->successful()) {
            $this->error("Download failed: HTTP {$response->status()}");
            return self::FAILURE;
        }

        $domains = $this->parse($response->body());
        $min = (int) $this->option('min');

        // Uma página de erro ou lista truncada não pode substituir a lista boa.
        if (count($domains) < $min) {
            $this->error('The list has only ' . count($domains) . " valid domain(s) (min {$min}); keeping the current one.");
            return self::FAILURE;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("Could not create {$directory}.");
            return self::FAILURE;
        }

        // Grava ao lado e renomeia: quem estiver lendo nunca vê um arquivo pela metade.
        $temporary = $path . '.tmp';
        file_put_contents($temporary, implode("\n", $domains) . "\n");
        rename($temporary, $path);

        $this->info(count($domains) . " disposable domains saved to {$path}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parse(string $body): array
    {
        $domains = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = strtolower(trim($line));

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $line)) {
                $domains[$line] = true;
            }
        }

        return array_keys($domains);
    }
}
