<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Features\EmailValidator\Console\DisposableUpdateCommand;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
use RiseTechApps\RiseTools\Features\HealthCheck\Doctor;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Features\HealthCheck\DoctorCommand;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\Console\DatabaseHealthCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotCreateCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotRestoreCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotListCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotDeleteCommand;
use RiseTechApps\RiseTools\Http\Controllers\HealthController;
use Illuminate\Queue\Events\JobProcessing;
use Symfony\Component\HttpFoundation\Response;

class RiseToolsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerMacrosResponse();

        // O NPlusOneDetector guarda contagens em estado estático. Em processo
        // longo (Octane, worker de fila) elas passariam de um request/job para
        // o próximo: zera no início de cada um. (A classe do Octane é só uma
        // string: sem o Octane instalado, o evento nunca dispara.)
        $this->app['events']->listen(
            [JobProcessing::class, 'Laravel\Octane\Events\RequestReceived'],
            fn() => NPlusOneDetector::clearStats()
        );

        $this->registerSchedulerHeartbeat();
        $this->registerHealthRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('risetools.php'),
            ], 'risetools-config');

            $this->commands([
                DoctorCommand::class,
                DatabaseHealthCommand::class,
                SnapshotCreateCommand::class,
                SnapshotRestoreCommand::class,
                SnapshotListCommand::class,
                SnapshotDeleteCommand::class,
                DisposableUpdateCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'risetools');

        // Accessor da RiseToolsFacade (alias "RiseTools" no composer.json).
        $this->app->singleton('rise-tools', fn($app) => new RiseTools());
        $this->app->alias('rise-tools', RiseTools::class);

        $this->app->singleton(Device::class, fn($app) => new Device());
        $this->app->singleton(EmailValidator::class, fn($app) => new EmailValidator());
        $this->app->singleton(NPlusOneDetector::class, fn($app) => new NPlusOneDetector());
        $this->app->singleton(DatabaseSnapshot::class, fn($app) => new DatabaseSnapshot());
        $this->app->singleton(DatabaseHealthMonitor::class, fn($app) => new DatabaseHealthMonitor());
        $this->app->singleton(Doctor::class, fn($app) => new Doctor($app));
    }

    /**
     * Grava um timestamp a cada minuto pelo scheduler; o check "scheduler" do
     * doctor falha quando ele fica velho (schedule:run parado).
     */
    protected function registerSchedulerHeartbeat(): void
    {
        if (!config('risetools.health.scheduler.heartbeat', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn() => Doctor::heartbeatCache()->forever(Doctor::HEARTBEAT_KEY, time()))
                ->everyMinute()
                ->name('risetools:scheduler-heartbeat');
        });
    }

    protected function registerHealthRoutes(): void
    {
        if (!config('risetools.health.route.enabled', false) || $this->app->routesAreCached()) {
            return;
        }

        $path = trim((string) config('risetools.health.route.path', 'health'), '/');

        Route::middleware((array) config('risetools.health.route.middleware', []))->group(function () use ($path) {
            Route::get("{$path}/live", [HealthController::class, 'live'])->name('risetools.health.live');
            Route::get("{$path}/ready", [HealthController::class, 'ready'])->name('risetools.health.ready');
        });
    }

    protected function registerMacrosResponse(): void
    {
        if (!ResponseFactory::hasMacro('jsonBase')) {
            ResponseFactory::macro('jsonBase', function (bool $success, ?string $message = null, array|JsonResource|null $data = null, int $code = Response::HTTP_OK) {
                $response = [
                    'success' => $success,
                    'code' => $code,
                ];

                if ($message) {
                    $response['message'] = $message;
                }

                // null = sem dados. [] continua indo como "data": [] (lista vazia).
                if ($data instanceof JsonResource) {
                    // Mesmo caminho do Laravel: inclui meta/links da paginação,
                    // with() e additional(), que o jsonSerialize() descartava.
                    $payload = $data->response()->getData(true);
                    $wrap = $data::$wrap;

                    if ($wrap !== null && is_array($payload) && array_key_exists($wrap, $payload)) {
                        $response['data'] = $payload[$wrap];
                        unset($payload[$wrap]);

                        // meta, links e extras ficam no mesmo nível de "data",
                        // sem sobrescrever success/code/message.
                        foreach ($payload as $key => $value) {
                            if (!array_key_exists($key, $response)) {
                                $response[$key] = $value;
                            }
                        }
                    } else {
                        // Resource com withoutWrapping(): o payload inteiro é o dado.
                        $response['data'] = $payload;
                    }
                } elseif ($data !== null) {
                    $response['data'] = $data;
                }

                return response()->json($response, $code);
            });
        }

        if (!ResponseFactory::hasMacro('jsonSuccess')) {
            ResponseFactory::macro('jsonSuccess', fn(array|JsonResource|null $data = null, string $message = 'Operation completed successfully.') => response()->jsonBase(true, $message, $data, Response::HTTP_OK));
        }

        if (!ResponseFactory::hasMacro('jsonCreated')) {
            ResponseFactory::macro('jsonCreated', fn(array|JsonResource|null $data = null, string $message = 'Resource created successfully.') => response()->jsonBase(true, $message, $data, Response::HTTP_CREATED));
        }

        if (!ResponseFactory::hasMacro('jsonNoContent')) {
            // 204 não pode ter corpo (RFC 9110): sem envelope.
            ResponseFactory::macro('jsonNoContent', fn(array $headers = []) => response()->noContent(Response::HTTP_NO_CONTENT, $headers));
        }

        if (!ResponseFactory::hasMacro('jsonError')) {
            ResponseFactory::macro('jsonError', fn(string $message = 'Resource not available.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (!ResponseFactory::hasMacro('jsonValidation')) {
            // Mesmo formato de erros do Laravel ("errors" => campo => mensagens), dentro do envelope.
            ResponseFactory::macro('jsonValidation', function (array|MessageBag $errors, string $message = 'The given data was invalid.') {
                $response = response()->jsonBase(false, $message, null, Response::HTTP_UNPROCESSABLE_ENTITY);
                $payload = $response->getData(true);
                $payload['errors'] = $errors instanceof MessageBag ? $errors->toArray() : $errors;

                return $response->setData($payload);
            });
        }

        if (!ResponseFactory::hasMacro('jsonUnauthorized')) {
            ResponseFactory::macro('jsonUnauthorized', fn(string $message = 'Unauthenticated.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_UNAUTHORIZED));
        }

        if (!ResponseFactory::hasMacro('jsonForbidden')) {
            ResponseFactory::macro('jsonForbidden', fn(string $message = 'This action is unauthorized.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_FORBIDDEN));
        }

        if (!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', fn(string $message = 'Resource no longer available.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_GONE));
        }

        if (!ResponseFactory::hasMacro('jsonNotFound')) {
            ResponseFactory::macro('jsonNotFound', fn(string $message = 'Resource not found.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_NOT_FOUND));
        }

        if (!ResponseFactory::hasMacro('jsonInternal')) {
            ResponseFactory::macro('jsonInternal', fn(string $message = 'Internal server error.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_INTERNAL_SERVER_ERROR));
        }
    }

}
