<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\Console\HealthCheckCommand;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotCreateCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotDeleteCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotListCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console\SnapshotRestoreCommand;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
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
                SnapshotCreateCommand::class,
                SnapshotRestoreCommand::class,
                SnapshotListCommand::class,
                SnapshotDeleteCommand::class,
                HealthCheckCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton(Device::class, function ($app) {
            return new Device();
        });

        $this->app->singleton(AvatarGenerator::class, function ($app) {
            return new AvatarGenerator();
        });

        $this->app->singleton(EmailValidator::class, function ($app) {
            return new EmailValidator();
        });

        $this->app->singleton(DatabaseSnapshot::class, function ($app) {
            return new DatabaseSnapshot();
        });

        $this->app->singleton(DatabaseHealthMonitor::class, function ($app) {
            return new DatabaseHealthMonitor();
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
            ResponseFactory::macro('jsonSuccess', function (array|JsonResource|null $data = null, string $message = 'Operation completed successfully.') {
                return response()->jsonBase(true, $message, $data, Response::HTTP_OK);
            });
        }

        if (!ResponseFactory::hasMacro('jsonError')) {
            ResponseFactory::macro('jsonError', function (string $message = 'Resource not available.', array|JsonResource|null $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_UNPROCESSABLE_ENTITY);
            });
        }

        if (!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', function (string $message = 'Recurso não disponível.', array|JsonResource|null $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_GONE);
            });
        }

        if (!ResponseFactory::hasMacro('jsonNotFound')) {
            ResponseFactory::macro('jsonNotFound', function (string $message = 'Resource not found.', array|JsonResource|null $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_NOT_FOUND);
            });
        }

        if (!ResponseFactory::hasMacro('jsonInternal')) {
            ResponseFactory::macro('jsonInternal', function (string $message = 'Internal server error.', array|JsonResource|null $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_INTERNAL_SERVER_ERROR);
            });
        }
    }

}
