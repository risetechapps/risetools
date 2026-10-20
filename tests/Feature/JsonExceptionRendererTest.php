<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RiseTechApps\RiseTools\Http\JsonExceptionRenderer;
use RiseTechApps\RiseTools\Tests\TestCase;

class JsonExceptionRendererTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('api/validation', fn() => throw ValidationException::withMessages(['email' => 'The email field is required.']));
        Route::get('api/auth', fn() => throw new AuthenticationException());
        Route::get('api/forbidden', fn() => throw new AuthorizationException('Not your order.'));
        Route::get('api/model', fn() => throw (new ModelNotFoundException())->setModel(SecretModel::class, [5]));
        Route::get('api/throttle', fn() => abort(429, 'Too Many Attempts.', ['Retry-After' => '30']));
        Route::get('api/crash', fn() => throw new \RuntimeException('db password is hunter2'));
        Route::get('web/crash', fn() => throw new \RuntimeException('web'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        JsonExceptionRenderer::register(new Exceptions($this->app->make(ExceptionHandler::class)));
    }

    public function test_validation(): void
    {
        $this->getJson('api/validation')
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'code' => 422,
                'message' => 'The email field is required.',
                'errors' => ['email' => ['The email field is required.']],
            ]);
    }

    public function test_authentication_and_authorization(): void
    {
        $this->getJson('api/auth')->assertStatus(401)->assertJson(['success' => false, 'code' => 401, 'message' => 'Unauthenticated.']);
        $this->getJson('api/forbidden')->assertStatus(403)->assertJson(['success' => false, 'code' => 403, 'message' => 'Not your order.']);
    }

    public function test_model_not_found_does_not_leak_the_class(): void
    {
        $response = $this->getJson('api/model')->assertStatus(404)->assertJson(['message' => 'Resource not found.']);

        $this->assertStringNotContainsString('SecretModel', $response->getContent());
    }

    public function test_unknown_route(): void
    {
        $this->getJson('api/nope')->assertStatus(404)->assertJson(['success' => false, 'code' => 404]);
    }

    public function test_http_exception_keeps_status_and_headers(): void
    {
        $this->getJson('api/throttle')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['code' => 429, 'message' => 'Too Many Attempts.']);
    }

    public function test_server_error_hides_details_without_debug(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('api/crash')->assertStatus(500)->assertExactJson([
            'success' => false, 'code' => 500, 'message' => 'Internal server error.',
        ]);

        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_server_error_has_details_with_debug(): void
    {
        config(['app.debug' => true]);

        $this->getJson('api/crash')->assertStatus(500)->assertJsonPath('data.exception', \RuntimeException::class);
    }

    public function test_non_json_requests_keep_the_default_rendering(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('web/crash')->assertStatus(500);

        $this->assertStringNotContainsString('"success"', $response->getContent());
    }

}

class JsonExceptionRendererCustomRuleTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('web/crash', fn() => throw new \RuntimeException('web'));
        Route::get('api/crash', fn() => throw new \RuntimeException('api'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        JsonExceptionRenderer::register(
            new Exceptions($this->app->make(ExceptionHandler::class)),
            fn($request) => $request->is('web/*')
        );
    }

    public function test_custom_rule_decides_where_it_applies(): void
    {
        $this->get('web/crash')->assertStatus(500)->assertJson(['success' => false, 'code' => 500]);

        // Fora da regra (mesmo em api/*): renderização padrão do Laravel.
        $this->assertStringNotContainsString('"success"', $this->get('api/crash')->getContent());
    }
}

class SecretModel extends Model
{
}
