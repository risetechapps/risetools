<?php

namespace RiseTechApps\RiseTools\Features\AtomicJobChain;

use Closure;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Exception\MissingInputException;
use Throwable;
use TypeError;

class  AtomicJobChain implements ShouldQueue
{
    /** @var bool */
    public static bool $shouldBeQueuedByDefault = false;

    /** @var callable[]|string[] */
    public array $jobs;

    /** @var callable|null */
    public $send;

    public $passable;

    public bool $shouldBeQueued;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 900;

    public function __construct($jobs, callable $send = null, bool $shouldBeQueued = null)
    {
        $this->jobs = $jobs;
        $this->send = $send ?? function ($event) {
            return $event;
        };
        $this->shouldBeQueued = $shouldBeQueued ?? static::$shouldBeQueuedByDefault;
    }

    /** @param callable[]|string[] $jobs */
    public static function make(array $jobs): self
    {
        return new static($jobs);
    }

    public function send(callable $send): self
    {
        $this->send = $send;

        return $this;
    }

    public function shouldBeQueued(bool $shouldBeQueued = true): static
    {
        $this->shouldBeQueued = $shouldBeQueued;
        return $this;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();

        foreach ($this->jobs as $job) {

            try {

                if (is_string($job)) {
                    $job = [new $job(...$this->passable), 'handle'];
                }

                if (app()->runningInConsole()) {
                    $date = now();
                    $output->writeln("<info>  ${date} - Running JOB: " . get_class($job[0]) . "</info>");
                }


                $result = app()->call($job);


            } catch (TypeError|Throwable|Exception|MissingInputException $exception) {
                $date = now();
                $jobClass = is_object($job[0]) ? get_class($job[0]) : $job[0];
                $output->writeln("<info>  ${date} - ERROR JOB: " . $jobClass . " - " . $exception->getMessage() . "</info>");

                report($exception);

                if (method_exists(get_class($job[0]), 'failed')) {
                    call_user_func_array([$job[0], 'failed'], [$exception]);
                } else {
                    throw $exception;
                }
                break;
            }

            if ($result === false) {
                break;
            }
        }
    }

    /**
     * Generate a closure that can be used as a listener.
     */
    public function toListener(): ?Closure
    {
        if (empty($this->jobs)) {
            return function (...$args) {
            };
        }

        return function (...$args) {
            $executable = $this->executable($args);

            if ($this->shouldBeQueued) {
                if (DB::transactionLevel() > 0) {
                    // Despachar o job para a fila manualmente após o commit da transação
                    DB::afterCommit(function () use ($executable) {
                        dispatch($executable);
                    });
                } else {
                    // Caso não haja transação, o job é disparado imediatamente
                    dispatch($executable);
                }
            }
        };
    }

    /**
     * Return a serializable version of the current object.
     */
    public function executable($listenerArgs): self
    {
        $clone = clone $this;

        $passable = ($clone->send)(...$listenerArgs);
        $passable = is_array($passable) ? $passable : [$passable];

        $clone->passable = $passable;
        $clone->send = null;

        return $clone;
    }


}
