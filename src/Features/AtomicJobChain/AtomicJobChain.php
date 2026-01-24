<?php

namespace RiseTechApps\RiseTools\Features\AtomicJobChain;

use Closure;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
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
     * Construtor da cadeia de Jobs.
     *
     * @param array $jobs A lista de Jobs a serem executados.
     * @param callable|null $send Callback para processar argumentos de eventos.
     * @param bool|null $shouldBeQueued Define se deve ser enfileirado.
     */
    public function __construct($jobs, callable $send = null, bool $shouldBeQueued = null)
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
        $this->onSuccess = $callback;
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
        $this->onFailure = $callback;
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
        $this->onFinally = $callback;
        return $this;
    }

    /**
     * O nome que será exibido no Laravel Horizon.
     *
     * @return string
     */
    public function displayName(): string
    {
        // Mapeia os Jobs para seus nomes de classe base para exibição
        $jobNames = array_map(function ($job) {
            return is_string($job) ? class_basename($job) : 'Closure';
        }, $this->jobs);

        // Retorna um nome descritivo para o Horizon
        return 'Atomic Chain: ' . implode(', ', $jobNames);
    }

    /**
     * Executa a cadeia de Jobs.
     *
     * @throws Throwable
     */
    public function handle(): void
    {

        $output = new ConsoleOutput();
        $hasFailed = false;

        try {
            // Itera sobre cada Job na cadeia
            foreach ($this->jobs as $job) {
                try {
                    // Prepara o Job para execução (instancia se for string)
                    if (is_string($job)) {
                        $job = [new $job(...$this->passable), 'handle'];
                    }

                    // Loga a execução no console (útil para workers)
                    if (app()->runningInConsole()) {
                        $date = now();
                        $output->writeln("<info>  ${date} - Running JOB: " . get_class($job[0]) . "</info>");
                    }

                    // Executa o Job
                    $result = app()->call($job);

                } catch (TypeError|Throwable|Exception|MissingInputException $exception) {
                    // Captura qualquer falha
                    $hasFailed = true;

                    // Executa o callback de falha (catch)
                    if ($this->onFailure) {
                        app()->call($this->onFailure, ['exception' => $exception]);
                    }

                    // Prepara a exceção para o Horizon
                    $jobClass = is_object($job[0]) ? get_class($job[0]) : $job[0];
                    $wrapperException = new \Exception(
                        "Job [{$jobClass}] failed: " . $exception->getMessage(),
                        $exception->getCode(),
                        $exception
                    );

                    // Reporta a exceção e chama o método failed() do Job interno
                    report($wrapperException);
                    if (method_exists($job[0], 'failed')) {
                        call_user_func([$job[0], 'failed'], $exception);
                    }

                    // Lança a exceção para marcar o Job pai como falho no Horizon
                    throw $wrapperException;
                }

                // Interrompe a cadeia se o Job retornar explicitamente 'false'
                if ($result === false) {
                    break;
                }
            }

            // Se não houve falhas, executa o callback de sucesso (then)
            if (!$hasFailed && $this->onSuccess) {
                app()->call($this->onSuccess);
            }

        } finally {
            // Executa o callback de finalização (finally) sempre
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
            return function (...$args) {
            };
        }

        return function (...$args) {
            $executable = $this->executable($args);

            if ($this->shouldBeQueued) {
                if (DB::transactionLevel() > 0) {
                    // Despacha o Job para a fila manualmente após o commit da transação
                    DB::afterCommit(function () use ($executable) {
                        dispatch($executable);
                    });
                } else {
                    // Caso não haja transação, o Job é disparado imediatamente
                    dispatch($executable);
                }
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
}
