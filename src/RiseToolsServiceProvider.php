<?php

namespace RiseTechApps\RiseTools;

use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
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

    }

    protected function registerMacrosResponse(): void
    {
        if (!ResponseFactory::hasMacro('jsonBase')) {
            ResponseFactory::macro('jsonBase', function (bool $success, string $message = null, array $data = null, int $code = Response::HTTP_OK) {
                $response = [
                    'success' => $success,
                    'code' => $code,
                ];

                if ($message) {
                    $response['message'] = $message;
                }

                if (!empty($data)) {
                    $response['data'] = $data;
                }

                return response()->json($response, $code);
            });
        }

        if (!ResponseFactory::hasMacro('jsonSuccess')) {
            ResponseFactory::macro('jsonSuccess', function ($data = [], string $message = 'Operation completed successfully.') {
                return response()->jsonBase(true, $message, $data, Response::HTTP_OK);
            });
        }

        if (!ResponseFactory::hasMacro('jsonError')) {
            ResponseFactory::macro('jsonError', function (string $message = 'Resource not available.', array $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_UNPROCESSABLE_ENTITY);
            });
        }

        if (!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', function (string $message = 'Recurso não disponível.', array $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_GONE);
            });
        }

        if (!ResponseFactory::hasMacro('jsonNotFound')) {
            ResponseFactory::macro('jsonNotFound', function (string $message = 'Resource not found.', array $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_NOT_FOUND);
            });
        }

        if (!ResponseFactory::hasMacro('jsonInternal')) {
            ResponseFactory::macro('jsonInternal', function (string $message = 'Internal server error.', array $data = null) {
                return response()->jsonBase(false, $message, $data, Response::HTTP_INTERNAL_SERVER_ERROR);
            });
        }
    }

}
