<?php

namespace RiseTechApps\RiseTools\Http;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Faz as exceções saírem no mesmo envelope das macros (success/code/message/data).
 *
 * Opcional, no bootstrap/app.php:
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         JsonExceptionRenderer::register($exceptions);
 *     })
 *
 * Por padrão só vale para requests que esperam JSON ou em api/*; passe $when
 * para outra regra. O report (log) das exceções não muda.
 */
class JsonExceptionRenderer
{
    /**
     * @param (Closure(Request, Throwable): bool)|null $when
     */
    public static function register(Exceptions $exceptions, ?Closure $when = null): void
    {
        $exceptions->render(function (Throwable $e, Request $request) use ($when) {
            $applies = $when
                ? $when($request, $e)
                : ($request->expectsJson() || $request->is('api/*'));

            return $applies ? static::render($e) : null;
        });
    }

    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->jsonValidation($e->errors(), $e->getMessage())->setStatusCode($e->status);
        }

        if ($e instanceof AuthenticationException) {
            return response()->jsonUnauthorized($e->getMessage() ?: 'Unauthenticated.');
        }

        // O Handler do Laravel já converteu ModelNotFoundException/AuthorizationException
        // em HttpException (404/403) antes de chegar aqui.
        if ($e instanceof HttpExceptionInterface) {
            return static::fromHttpException($e);
        }

        $data = config('app.debug') ? static::debugData($e) : null;

        return response()->jsonInternal('Internal server error.', $data);
    }

    protected static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        // "No query results for model [App\Models\User] 5" expõe a classe: mensagem genérica.
        $message = $e->getPrevious() instanceof ModelNotFoundException
            ? 'Resource not found.'
            : ($e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'));

        $data = $status >= 500 && config('app.debug') ? static::debugData($e) : null;

        return response()
            ->jsonBase($status < 400, $message, $data, $status)
            ->withHeaders($e->getHeaders());
    }

    protected static function debugData(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(array_map(
                fn($frame) => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?'),
                $e->getTrace()
            ), 0, 10),
        ];
    }
}
