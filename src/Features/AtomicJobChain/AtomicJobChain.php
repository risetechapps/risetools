<?php

namespace RiseTechApps\RiseTools\Features\AtomicJobChain;

use Closure;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;
use TypeError;

/**
 * @class AtomicJobChain
 *
 * Implementa uma cadeia de Jobs atômica para o Laravel, garantindo que a execução
 * seja interrompida na primeira falha. Oferece callbacks de sucesso, falha e finalização
 * (then, catch, finally) de forma similar aos Batches do Laravel.
 *
 * @implements ShouldQueue
 */
class AtomicJobChain implements ShouldQueue
{
    /**
     * Define se a cadeia deve ser despachada para a fila por padrão.
     *
     * @var bool
     */
    public static bool $shouldBeQueuedByDefault = false;

    /**
     * A lista de Jobs a serem executados em sequência.
     * Pode conter nomes de classes de Jobs ou Closures.
     *
     * @var callable[]|string[]
     */
    public array $jobs;

    /**
     * Callback usado para transformar os argumentos do evento de disparo em argumentos para os Jobs internos.
     *
     * @var callable|null
     */
    public $send;

    /**
     * Argumentos que serão passados para o construtor/método handle dos Jobs internos.
     *
     * @var mixed
     */
    public $passable;

    /**
     * Indica se a cadeia deve ser despachada para a fila.
     *
     * @var bool
     */
    public bool $shouldBeQueued;

    /**
     * O número de segundos que o Job pode rodar antes de atingir o timeout.
     *
     * @var int
     */
    public int $timeout = 9000;

    /**
     * Callback a ser executado se todos os Jobs forem concluídos com sucesso. (then)
     *
     * @var callable|null
     */
    public $onSuccess;

    /**
     * Callback a ser executado se algum Job na cadeia falhar. (catch)
     *
     * @var callable|null
     */
    public $onFailure;

    /**
     * Callback a ser executado sempre, independente de sucesso ou falha. (finally)
     *
     * @var callable|null
     */
    public $onFinally;


    /**
     * Callback executado após cada Job completar com sucesso.
     * Recebe: (string $jobClass, mixed $passable)
     *
     * @var callable|null
     */
    public $onStepComplete;

    /**
     * Construtor da cadeia de Jobs.
     *
     * @param array $jobs A lista de Jobs a serem executados.
     * @param callable|null $send Callback para processar argumentos de eventos.
     * @param bool|null $shouldBeQueued Define se deve ser enfileirado.
     */
    public function __construct(array $jobs, ?callable $send = null, ?bool $shouldBeQueued = null)
    {
        $this->jobs = $jobs;
        $this->send = $send ?? function ($event) {
            return $event;
        };
        $this->shouldBeQueued = $shouldBeQueued ?? static::$shouldBeQueuedByDefault;
    }

    /**
     * Construtor estático para iniciar a cadeia de Jobs.
     *
     * @param callable[]|string[] $jobs A lista de Jobs.
     * @return self
     */
    public static function make(array $jobs): self
    {
        return new static($jobs);
    }

    /**
     * Define o callback que prepara os argumentos para os Jobs internos.
     *
     * @param callable $send
     * @return self
     */
    public function send(callable $send): self
    {
        $this->send = $send;

        return $this;
    }

    /**
     * Define se a cadeia deve ser despachada para a fila.
     *
     * @param bool $shouldBeQueued
     * @return static
     */
    public function shouldBeQueued(bool $shouldBeQueued = true): static
    {
        $this->shouldBeQueued = $shouldBeQueued;
        return $this;
    }

    /**
     * Define o callback a ser executado em caso de sucesso total. (then)
     *
     * @param callable $callback
     * @return self
     */
    public function then(callable $callback): self
    {
        $this->onSuccess = $callback instanceof \Closure
            ? new SerializableClosure($callback)
            : $callback;
        return $this;
    }

    /**
     * Define o callback a ser executado em caso de falha. (catch)
     * Recebe a exceção (Throwable) como argumento.
     *
     * @param callable $callback
     * @return self
     */
    public function catch(callable $callback): self
    {
        $this->onFailure = $callback instanceof \Closure
            ? new SerializableClosure($callback)
            : $callback;
        return $this;
    }

    /**
     * Define o callback a ser executado sempre, independente de sucesso ou falha. (finally)
     *
     * @param callable $callback
     * @return self
     */
    public function finally(callable $callback): self
    {
        $this->onFinally = $callback instanceof \Closure
            ? new SerializableClosure($callback)
            : $callback;
        return $this;
    }

    /**
     * O nome que será exibido no Laravel Horizon.
     *
     * @return string
     */
    public function displayName(): string
    {
        $total    = count($this->jobs);
        $jobNames = array_map(
            fn($job) => is_string($job) ? class_basename($job) : 'Closure',
            $this->jobs
        );

        return "Atomic Chain ({$total}): " . implode(' → ', $jobNames);
    }

    /**
     * Define callback executado após cada Job com sucesso.
     */
    public function onStepComplete(callable $callback): self
    {
        $this->onStepComplete = $callback instanceof \Closure
            ? new SerializableClosure($callback)
            : $callback;
        return $this;
    }

    /**
     * Executa a cadeia de Jobs.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $output    = new ConsoleOutput();
        $hasFailed = false;

        try {
            foreach ($this->jobs as $job) {

                // ✅ Resolve o nome ANTES do try — fica correto tanto no
                // onStepComplete (sucesso) quanto no catch (falha), mesmo
                // depois que $job é reatribuído de string para [objeto, 'handle']
                $jobClass = match(true) {
                    is_string($job)                      => $job,
                    is_array($job) && is_object($job[0]) => get_class($job[0]),
                    is_array($job)                       => (string) $job[0],
                    $job instanceof Closure              => 'Closure',
                    default                              => 'UnknownJob',
                };

                try {
                    // Instancia o Job se vier como string de classe
                    if (is_string($job)) {
                        $passable = is_array($this->passable) ? $this->passable : (array) $this->passable;
                        $job      = [new $job(...$passable), 'handle'];
                    }

                    if (app()->runningInConsole()) {
                        $output->writeln("<info>  " . now() . " - Running JOB: {$jobClass}</info>");
                    }

                    $result = app()->call($job);

                    // ✅ Grava checkpoint após cada Job bem-sucedido —
                    // qualquer job, interno ou de pacote externo
                    if ($this->onStepComplete) {
                        app()->call($this->onStepComplete, [
                            'jobClass' => $jobClass,
                            'passable' => is_array($this->passable) ? $this->passable : [],
                        ]);
                    }

                } catch (TypeError|Throwable|Exception|MissingInputException $exception) {
                    $hasFailed = true;

                    if ($this->onFailure) {
                        app()->call($this->onFailure, ['exception' => $exception]);
                    }

                    // $jobClass já está resolvido corretamente acima
                    $wrapperException = new \Exception(
                        "Job [{$jobClass}] failed: " . $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception
                    );

                    report($wrapperException);

                    if (is_array($job) && isset($job[0]) && method_exists($job[0], 'failed')) {
                        call_user_func([$job[0], 'failed'], $exception);
                    }

                    throw $wrapperException;
                }

                if ($result === false) {
                    break;
                }
            }

            if (!$hasFailed && $this->onSuccess) {
                app()->call($this->onSuccess);
            }

        } finally {
            if ($this->onFinally) {
                app()->call($this->onFinally);
            }
        }
    }

    /**
     * Gera uma Closure que pode ser usada como um Listener de Eventos.
     *
     * @return Closure|null
     */
    public function toListener(): ?Closure
    {
        if (empty($this->jobs)) {
            return fn(...$args) => null;
        }

        return function (...$args) {
            $executable = $this->executable($args);

            if ($this->shouldBeQueued) {
                if (DB::transactionLevel() > 0) {
                    DB::afterCommit(fn() => dispatch($executable));
                } else {
                    dispatch($executable);
                }
                return;
            }

            // ✅ Executa síncrono quando shouldBeQueued = false
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn() => $executable->handle());
            } else {
                $executable->handle();
            }
        };
    }

    /**
     * Retorna uma versão serializável do objeto atual, pronta para ser despachada.
     *
     * @param array $listenerArgs Argumentos recebidos pelo Listener.
     * @return self
     */
    public function executable($listenerArgs): self
    {
        $clone = clone $this;

        // Processa os argumentos do Listener através do callback $send
        $passable = ($clone->send)(...$listenerArgs);
        $passable = is_array($passable) ? $passable : [$passable];

        $clone->passable = $passable;
        // Remove o callback $send para evitar problemas de serialização desnecessários
        $clone->send = null;

        return $clone;
    }

    // ✅ Tags aparecem no Horizon e facilitam debug por tenant
    public function tags(): array
    {
        $tags = ['atomic-chain'];

        // Adiciona o tenant como tag se passable tiver um Model com getKey()
        $passable = is_array($this->passable) ? $this->passable : [];
        foreach ($passable as $arg) {
            if ($arg instanceof \Illuminate\Database\Eloquent\Model) {
                $tags[] = 'tenant:' . $arg->getKey();
                break;
            }
        }

        foreach ($this->jobs as $job) {
            if (is_string($job)) {
                $tags[] = 'job:' . class_basename($job);
            }
        }

        return $tags;
    }
}
