<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\RiseTools\Features\Device\Device;
use Symfony\Component\HttpFoundation\Response;

class RiseToolsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerMacrosResponse();
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton(Device::class, function ($app) {
            return new Device();
        });
    }

    protected function registerMacrosResponse(): void
    {
        if (!ResponseFactory::hasMacro('jsonBase')) {
            ResponseFactory::macro('jsonBase', function (bool $success, string $message = null, array|JsonResource $data = null, int $code = Response::HTTP_OK) {
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
            ResponseFactory::macro('jsonSuccess', function (array|JsonResource $data = null, string $message = 'Operation completed successfully.') {
                return response()->jsonBase(true, $message, $data, Response::HTTP_OK);
            });
        }

        if (!ResponseFactory::hasMacro('jsonError')) {
            ResponseFactory::macro('jsonError', function (string $message = 'Resource not available.', array|JsonResource $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_UNPROCESSABLE_ENTITY);
            });
        }

        if (!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', function (string $message = 'Recurso não disponível.', array|JsonResource $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_GONE);
            });
        }

        if (!ResponseFactory::hasMacro('jsonNotFound')) {
            ResponseFactory::macro('jsonNotFound', function (string $message = 'Resource not found.', array|JsonResource $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_NOT_FOUND);
            });
        }

        if (!ResponseFactory::hasMacro('jsonInternal')) {
            ResponseFactory::macro('jsonInternal', function (string $message = 'Internal server error.', array|JsonResource $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_INTERNAL_SERVER_ERROR);
            });
        }
    }

}
