<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;
use RiseTechApps\RiseTools\Tests\TestCase;

class NPlusOneDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function test_enabling_twice_does_not_duplicate_the_listener(): void
    {
        NPlusOneDetector::enable()->threshold(100);
        NPlusOneDetector::enable();

        $this->runSelects(6);

        $this->assertSame(6, $this->topCount());
    }

    public function test_reports_n_plus_one_to_log_once(): void
    {
        Log::spy();
        NPlusOneDetector::enable()->threshold(3)->reportToLog()->suggestEagerLoading();

        $this->runSelects(5);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn($message) => str_contains($message, "[N+1 Query] 3 queries detected for table 'users'")
        );
        $this->assertArrayHasKey('select * from "users" where "id" = ?', NPlusOneDetector::getStats()['suspicious_patterns']);
    }

    public function test_job_processing_resets_the_state(): void
    {
        NPlusOneDetector::enable()->threshold(100);
        $this->runSelects(3);

        event(new JobProcessing('sync', new SyncJob($this->app, '{}', 'sync', 'default')));

        $this->assertSame(0, NPlusOneDetector::getStats()['total_unique_patterns']);
    }

    public function test_disabled_detector_does_not_count(): void
    {
        NPlusOneDetector::enable()->threshold(100);
        NPlusOneDetector::disable();

        $this->runSelects(3);

        $this->assertSame(0, NPlusOneDetector::getStats()['total_unique_patterns']);
    }

    private function runSelects(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::select('select * from "users" where "id" = ?', [$i]);
        }
    }

    private function topCount(): ?int
    {
        $top = NPlusOneDetector::getStats()['top_queries'];

        return array_values($top)[0]['count'] ?? null;
    }
}
