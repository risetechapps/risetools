<?php

declare(strict_types=1);

namespace RiseTechApps\RiseTools\Features\NPlusOneDetector;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NPlusOneDetector
{
    private static bool $enabled = false;
    private static int $threshold = 5;
    private static bool $reportToLog = false;
    private static bool $reportToSentry = false;
    private static bool $suggestEagerLoading = false;
    private static float $sampleRate = 1.0;
    private static array $queries = [];
    private static array $suspiciousPatterns = [];

    public static function enable(): self
    {
        self::$enabled = true;
        self::startListening();
        return new self();
    }

    public static function disable(): void
    {
        self::$enabled = false;
        self::$queries = [];
        self::$suspiciousPatterns = [];
    }

    public function threshold(int $count): self
    {
        self::$threshold = $count;
        return $this;
    }

    public function sampleRate(float $rate): self
    {
        self::$sampleRate = max(0.0, min(1.0, $rate));
        return $this;
    }

    public function reportToLog(): self
    {
        self::$reportToLog = true;
        return $this;
    }

    public function reportToSentry(): self
    {
        self::$reportToSentry = true;
        return $this;
    }

    public function suggestEagerLoading(): self
    {
        self::$suggestEagerLoading = true;
        return $this;
    }

    private static function startListening(): void
    {
        if (!self::$enabled) {
            return;
        }

        DB::listen(function ($query): void {
            try {
                if ((mt_rand() / mt_getrandmax()) > self::$sampleRate) {
                    return;
                }

                $sql = $query->sql;
                $bindings = $query->bindings;

                $pattern = self::normalizeQuery($sql);

                if (!isset(self::$queries[$pattern])) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
                    if ($trace === false) {
                        $trace = [];
                    }

                    self::$queries[$pattern] = [
                        'count' => 0,
                        'sql' => $sql,
                        'bindings_samples' => [],
                        'first_occurrence' => $trace,
                    ];
                }

                self::$queries[$pattern]['count']++;

                if (count(self::$queries[$pattern]['bindings_samples']) < 3) {
                    self::$queries[$pattern]['bindings_samples'][] = $bindings;
                }

                if (self::$queries[$pattern]['count'] === self::$threshold) {
                    self::detectNPlusOne($pattern);
                }
            } catch (\Throwable $e) {
                // Silencia erros
            }
        });
    }

    private static function normalizeQuery(string $sql): string
    {
        $normalized = $sql;

        $result = preg_replace("/'[^']*'/", "'?'", $normalized);
        if ($result !== null && is_string($result)) {
            $normalized = $result;
        }

        $result = preg_replace('/\b\d+\b/', '?', $normalized);
        if ($result !== null && is_string($result)) {
            $normalized = $result;
        }

        $result = preg_replace('/IN\s*\([^)]*\)/i', 'IN (?)', $normalized);
        if ($result !== null && is_string($result)) {
            $normalized = $result;
        }

        $result = preg_replace('/\s+/', ' ', trim($normalized));
        if ($result !== null && is_string($result)) {
            $normalized = $result;
        }

        return strtolower($normalized);
    }

    private static function detectNPlusOne(string $pattern): void
    {
        if (!isset(self::$queries[$pattern])) {
            return;
        }

        $queryData = self::$queries[$pattern];

        if (!is_array($queryData) || !isset($queryData['sql'])) {
            return;
        }

        $analysis = self::analyzeQuery($queryData['sql']);

        if (empty($analysis['is_select'])) {
            return;
        }

        $isLikelyNPlusOne = !empty($analysis['has_single_id_condition'])
                           && empty($analysis['has_join'])
                           && empty($analysis['is_aggregate']);

        if (!$isLikelyNPlusOne) {
            return;
        }

        $suggestion = [];

        if (self::$suggestEagerLoading) {
            $suggestion = self::suggestEagerLoadingFix($queryData, $analysis);
        }

        $report = [
            'type' => 'N+1 Query Detected',
            'pattern' => $pattern,
            'table' => $analysis['table'] ?? 'unknown',
            'count' => $queryData['count'] ?? 0,
            'threshold' => self::$threshold,
            'sql_sample' => isset($queryData['sql']) ? substr($queryData['sql'], 0, 200) : '',
            'suggestion' => $suggestion,
            'trace' => self::getRelevantTrace($queryData['first_occurrence'] ?? []),
        ];

        self::sendReport($report);
    }

    private static function analyzeQuery(string $sql): array
    {
        $analysis = [
            'is_select' => false,
            'table' => null,
            'has_single_id_condition' => false,
            'has_join' => false,
            'is_aggregate' => false,
        ];

        $sqlLower = strtolower($sql);

        $analysis['is_select'] = str_starts_with(trim($sqlLower), 'select');

        if (!$analysis['is_select']) {
            return $analysis;
        }

        $matches = [];
        if (preg_match('/\bfrom\s+[`"]?([a-z_]+)/i', $sql, $matches) === 1 && isset($matches[1])) {
            $analysis['table'] = $matches[1];
        }

        $hasIdCondition = preg_match(
            '/where.*[`"]?(id|user_id|model_id)[`"]?\s*=\s*\?/i',
            $sql
        );
        $analysis['has_single_id_condition'] = $hasIdCondition === 1;

        $analysis['has_join'] = str_contains($sqlLower, 'join');

        $isAggregate = preg_match('/\b(count|sum|avg|max|min)\s*\(/i', $sql);
        $analysis['is_aggregate'] = $isAggregate === 1;

        return $analysis;
    }

    private static function suggestEagerLoadingFix(array $queryData, array $analysis): array
    {
        $suggestion = [];
        $table = $analysis['table'] ?? null;

        if (empty($table) || !is_string($table)) {
            return $suggestion;
        }

        $modelGuess = self::guessModelFromTable($table);

        $suggestion['problem'] = "Múltiplas queries para a tabela '{$table}' detectadas";
        $suggestion['likely_cause'] = "Acesso a relacionamento sem eager loading";

        if (!empty($modelGuess)) {
            $suggestion['recommended_fix'] = "Adicione ->with(['relation']) ao carregar {$modelGuess}";
            $suggestion['code_example'] = "{$modelGuess}::with(['comments'])->get();";
        }

        $bindingSamples = $queryData['bindings_samples'] ?? [];
        if (count($bindingSamples) >= 2 && !empty($modelGuess)) {
            $ids = array_column($bindingSamples, 0);
            $ids = array_slice($ids, 0, 5);
            $idsList = implode(', ', $ids);

            $suggestion['alternative_fix'] = "Use whereIn() para carregar todos de uma vez";
            $suggestion['whereIn_example'] = "{$modelGuess}::whereIn('id', [{$idsList}])->get();";
        }

        return $suggestion;
    }

    private static function guessModelFromTable(string $table): ?string
    {
        if ($table === '') {
            return null;
        }

        $result = preg_replace('/_table$|_tbl$/', '', $table);
        if ($result === null || $result === '') {
            return null;
        }
        $table = $result;

        $parts = explode('_', $table);
        $model = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $singular = rtrim($part, 's');
            if (str_ends_with($part, 'ies')) {
                $singular = substr($part, 0, -3) . 'y';
            }
            $model .= ucfirst($singular);
        }

        return $model !== '' ? $model : null;
    }

    private static function getRelevantTrace(array $trace): array
    {
        $filtered = [];

        foreach ($trace as $frame) {
            if (!is_array($frame) || !isset($frame['file']) || !is_string($frame['file'])) {
                continue;
            }

            $file = $frame['file'];

            if (str_contains($file, '/vendor/')) {
                continue;
            }
            if (str_contains($file, '\\vendor\\')) {
                continue;
            }
            if (str_contains($file, 'Illuminate\\')) {
                continue;
            }

            $filtered[] = [
                'file' => $file,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];

            if (count($filtered) >= 5) {
                break;
            }
        }

        return $filtered;
    }

    private static function sendReport(array $report): void
    {
        if (self::$reportToLog) {
            self::sendToLog($report);
        }

        if (self::$reportToSentry) {
            self::sendToSentry($report);
        }
    }

    private static function sendToLog(array $report): void
    {
        try {
            $table = isset($report['table']) && is_string($report['table']) ? $report['table'] : 'unknown';
            $count = isset($report['count']) && is_int($report['count']) ? $report['count'] : 0;
            $suggestion = isset($report['suggestion']) && is_array($report['suggestion']) ? $report['suggestion'] : [];

            $message = sprintf(
                "[N+1 Query] %d queries detected for table '%s'. %s",
                $count,
                $table,
                $suggestion['recommended_fix'] ?? ''
            );

            Log::warning($message, [
                'pattern' => $report['pattern'] ?? '',
                'sql_sample' => $report['sql_sample'] ?? '',
                'suggestion' => $suggestion,
            ]);
        } catch (\Throwable $e) {
            // Silencia erros de logging
        }
    }

    private static function sendToSentry(array $report): void
    {
        if (!function_exists('Sentry\configureScope')) {
            return;
        }

        try {
            \Sentry\configureScope(function ($scope) use ($report): void {
                if (!is_object($scope) || !method_exists($scope, 'setContext')) {
                    return;
                }

                $scope->setContext('n_plus_one', [
                    'table' => $report['table'] ?? '',
                    'count' => $report['count'] ?? 0,
                    'suggestion' => $report['suggestion'] ?? [],
                ]);
            });

            if (function_exists('Sentry\captureMessage')) {
                \Sentry\captureMessage(
                    "N+1 Query detected: " . ($report['count'] ?? 0) . " queries to " . ($report['table'] ?? 'unknown'),
                    \Sentry\Severity::warning()
                );
            }
        } catch (\Throwable $e) {
            // Silencia erros do Sentry
        }
    }

    public static function getStats(): array
    {
        return [
            'enabled' => self::$enabled,
            'total_unique_patterns' => count(self::$queries),
            'suspicious_patterns' => self::$suspiciousPatterns,
            'top_queries' => self::getTopQueries(),
        ];
    }

    private static function getTopQueries(): array
    {
        $queries = self::$queries;

        uasort($queries, static function ($a, $b): int {
            $aCount = isset($a['count']) && is_int($a['count']) ? $a['count'] : 0;
            $bCount = isset($b['count']) && is_int($b['count']) ? $b['count'] : 0;
            return $bCount <=> $aCount;
        });

        return array_slice($queries, 0, 10, true);
    }

    public static function clearStats(): void
    {
        self::$queries = [];
        self::$suspiciousPatterns = [];
    }
}
