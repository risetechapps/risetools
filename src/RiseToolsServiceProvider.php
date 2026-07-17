<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Features\HealthCheck\HealthCheckCommand;
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
            ]);
        }
    }

    /**
     * Register the application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(Device::class, fn($app) => new Device());
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
