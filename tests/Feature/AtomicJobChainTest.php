<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\AtomicJobChain\AtomicJobChain;
use RiseTechApps\RiseTools\Tests\TestCase;

class AtomicJobChainTest extends TestCase
{
    public static array $log = [];

    public static array $done = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$log = [];
    }

    public function test_runs_jobs_in_order_and_calls_then_and_finally(): void
    {
        $chain = AtomicJobChain::make([FirstJob::class, SecondJob::class])
            ->onStepComplete(fn(string $jobClass) => self::$log[] = 'step:' . class_basename($jobClass))
            ->then(fn() => self::$log[] = 'then')
            ->catch(fn() => self::$log[] = 'catch')
            ->finally(fn() => self::$log[] = 'finally');
        $chain->passable = ['order-1'];

        $chain->handle();

        $this->assertSame([
            'first:order-1', 'step:FirstJob',
            'second:order-1', 'step:SecondJob',
            'then', 'finally',
        ], self::$log);
    }

    public function test_stops_at_the_first_failure(): void
    {
        $chain = AtomicJobChain::make([FirstJob::class, FailingJob::class, SecondJob::class])
            ->then(fn() => self::$log[] = 'then')
            ->catch(fn(\Throwable $exception) => self::$log[] = 'catch:' . $exception->getMessage())
            ->finally(fn() => self::$log[] = 'finally');
        $chain->passable = ['x'];

        try {
            $chain->handle();
            $this->fail('The chain should rethrow the failure.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Job [' . FailingJob::class . '] failed: boom', $e->getMessage());
        }

        $this->assertSame(['first:x', 'catch:boom', 'failed-hook', 'finally'], self::$log);
    }

    public function test_returning_false_stops_the_chain(): void
    {
        $chain = AtomicJobChain::make([StopJob::class, SecondJob::class]);
        $chain->passable = ['x'];

        $chain->handle();

        $this->assertSame(['stop'], self::$log);
    }

    public function test_listener_runs_after_the_transaction_commits(): void
    {
        $listener = AtomicJobChain::make([FirstJob::class])
            ->send(fn(string $orderId) => [$orderId])
            ->toListener();

        DB::transaction(function () use ($listener) {
            $listener('order-9');
            self::$log[] = 'inside-transaction';
        });

        $this->assertSame(['inside-transaction', 'first:order-9'], self::$log);
    }

    public function test_a_retry_resumes_after_the_steps_already_done(): void
    {
        // Registro "persistente" (no app real: banco) que sobrevive às tentativas.
        self::$done = [];
        FlakyJob::$failuresLeft = 1;

        $chain = AtomicJobChain::make([FirstJob::class, FlakyJob::class, SecondJob::class])
            ->onStepComplete(fn(string $jobClass) => self::$done[] = $jobClass)
            ->skipWhen(fn(string $jobClass) => in_array($jobClass, self::$done, true));

        // A fila reexecuta a partir do payload original (o mesmo executable()
        // do toListener): cada tentativa é uma cópia desserializada, sem nada
        // do progresso em memória da anterior.
        $payload = serialize($chain->executable(['x']));

        try {
            unserialize($payload)->handle();
            $this->fail('The first attempt should fail.');
        } catch (\Exception) {
        }

        unserialize($payload)->handle();

        $this->assertSame(['first:x', 'flaky:x', 'second:x'], self::$log, 'first step ran again on the retry');
        $this->assertSame([FirstJob::class, FlakyJob::class, SecondJob::class], self::$done);
    }

    public function test_skip_when_receives_the_job_and_the_passable(): void
    {
        $chain = AtomicJobChain::make([FirstJob::class, SecondJob::class])
            ->skipWhen(function (string $jobClass, array $passable) {
                self::$log[] = 'ask:' . class_basename($jobClass) . ':' . $passable[0];

                return $jobClass === FirstJob::class;
            });
        $chain->passable = ['x'];

        $chain->handle();

        $this->assertSame(['ask:FirstJob:x', 'ask:SecondJob:x', 'second:x'], self::$log);
    }

    public function test_only_true_skips_and_closures_always_run(): void
    {
        $chain = AtomicJobChain::make([FirstJob::class, fn() => self::$log[] = 'closure'])
            // Valor "truthy" que não é true não pula (contrato explícito).
            ->skipWhen(fn(string $jobClass) => $jobClass === 'Closure' ? true : 1);
        $chain->passable = ['x'];

        $chain->handle();

        $this->assertSame(['first:x', 'closure'], self::$log);
    }

    public function test_display_name_and_tags(): void
    {
        $chain = AtomicJobChain::make([FirstJob::class, SecondJob::class]);

        $this->assertSame('Atomic Chain (2): FirstJob → SecondJob', $chain->displayName());
        $this->assertSame(['atomic-chain', 'job:FirstJob', 'job:SecondJob'], $chain->tags());
    }
}

class FirstJob
{
    public function __construct(public string $id) {}

    public function handle(): void
    {
        AtomicJobChainTest::$log[] = "first:{$this->id}";
    }
}

class SecondJob
{
    public function __construct(public string $id) {}

    public function handle(): void
    {
        AtomicJobChainTest::$log[] = "second:{$this->id}";
    }
}

class FailingJob
{
    public function __construct(public string $id) {}

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }

    public function failed(\Throwable $exception): void
    {
        AtomicJobChainTest::$log[] = 'failed-hook';
    }
}

class FlakyJob
{
    public static int $failuresLeft = 0;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        if (self::$failuresLeft-- > 0) {
            throw new \RuntimeException('flaky');
        }

        AtomicJobChainTest::$log[] = "flaky:{$this->id}";
    }
}

class StopJob
{
    public function __construct(public string $id) {}

    public function handle(): bool
    {
        AtomicJobChainTest::$log[] = 'stop';

        return false;
    }
}
