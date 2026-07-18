<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Features\HealthCheck\HealthCheckCommand;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\Console\HealthCheckCommand as DatabaseHealthCheckCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotCreateCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotRestoreCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotListCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotDeleteCommand;
use Symfony\Component\HttpFoundation\Response;

class RiseToolsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerMacrosResponse();

        if ($this->app->runningInConsole()) {
            $this->commands([
                HealthCheckCommand::class,
                DatabaseHealthCheckCommand::class,
                SnapshotCreateCommand::class,
                SnapshotRestoreCommand::class,
                SnapshotListCommand::class,
                SnapshotDeleteCommand::class,
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

        $this->app->singleton(Device::class, fn($app) => new Device());
        $this->app->singleton(EmailValidator::class, fn($app) => new EmailValidator());
        $this->app->singleton(NPlusOneDetector::class, fn($app) => new NPlusOneDetector());
        $this->app->singleton(DatabaseSnapshot::class, fn($app) => new DatabaseSnapshot());
        $this->app->singleton(DatabaseHealthMonitor::class, fn($app) => new DatabaseHealthMonitor());
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

                if (!empty($data)) {

                    if($data instanceof JsonResource) {
                        $response['data'] = $data->jsonSerialize();
                    }else{
                        $response['data'] = $data;
                    }
                }

                return response()->json($response, $code);
            });
        }

        if (!ResponseFactory::hasMacro('jsonSuccess')) {
            ResponseFactory::macro('jsonSuccess', fn(array|JsonResource|null $data = null, string $message = 'Operation completed successfully.') => response()->jsonBase(true, $message, $data, Response::HTTP_OK));
        }

        if (!ResponseFactory::hasMacro('jsonError')) {
            ResponseFactory::macro('jsonError', fn(string $message = 'Resource not available.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', fn(string $message = 'Recurso não disponível.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_GONE));
        }

        if (!ResponseFactory::hasMacro('jsonNotFound')) {
            ResponseFactory::macro('jsonNotFound', fn(string $message = 'Resource not found.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_NOT_FOUND));
        }

        if (!ResponseFactory::hasMacro('jsonInternal')) {
            ResponseFactory::macro('jsonInternal', fn(string $message = 'Internal server error.', array|JsonResource|null $data = null) => response()->jsonBase(false, $message, $data, Response::HTTP_INTERNAL_SERVER_ERROR));
        }
    }

}
