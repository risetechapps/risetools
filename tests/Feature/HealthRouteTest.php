<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use RiseTechApps\RiseTools\Tests\TestCase;

/**
 * As rotas são registradas no boot do provider: a config de cada cenário vai
 * no getEnvironmentSetUp, antes do boot.
 */
abstract class HealthRouteTestCase extends TestCase
{
    protected array $routeConfig = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('risetools.health.route', array_merge([
            'enabled' => true,
            'path' => 'health',
            'middleware' => [],
            'checks' => ['database', 'cache'],
            'token' => null,
            'allowed_ips' => [],
        ], $this->routeConfig));
    }
}

class HealthRouteDisabledTest extends TestCase
{
    public function test_routes_are_off_by_default(): void
    {
        $this->getJson('health/live')->assertNotFound();
        $this->getJson('health/ready')->assertNotFound();
    }
}

class HealthRouteTest extends HealthRouteTestCase
{
    public function test_live(): void
    {
        $this->getJson('health/live')->assertOk()->assertJson(['success' => true, 'data' => ['status' => 'ok']]);
    }

    public function test_ready_without_protection_hides_details(): void
    {
        $response = $this->getJson('health/ready')->assertOk()->assertJson(['success' => true, 'message' => 'Ready.']);

        $this->assertSame([['check' => 'database', 'ok' => true], ['check' => 'cache', 'ok' => true]], $response->json('data.checks'));
    }

    public function test_failing_check_returns_503(): void
    {
        config([
            'risetools.health.checks' => ['broken' => FailingCheck::class],
            'risetools.health.route.checks' => ['database', 'broken'],
        ]);

        $this->getJson('health/ready')->assertStatus(503)->assertJson(['success' => false, 'message' => 'Not ready.']);
    }

    public function test_unknown_checks_in_config_are_ignored(): void
    {
        config(['risetools.health.route.checks' => ['database', 'does-not-exist']]);

        $this->assertSame(['database'], array_column($this->getJson('health/ready')->assertOk()->json('data.checks'), 'check'));
    }
}

class HealthRouteTokenTest extends HealthRouteTestCase
{
    protected array $routeConfig = ['token' => 's3cret', 'path' => 'status'];

    public function test_live_needs_no_token(): void
    {
        $this->getJson('status/live')->assertOk();
    }

    public function test_ready_without_or_with_wrong_token_is_forbidden(): void
    {
        $this->getJson('status/ready')->assertForbidden();
        $this->getJson('status/ready', ['X-Health-Token' => 'wrong'])->assertForbidden();
    }

    public function test_token_header_or_bearer_shows_details(): void
    {
        $this->getJson('status/ready', ['X-Health-Token' => 's3cret'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['checks' => [['check', 'ok', 'detail', 'ms']]]]);

        $this->getJson('status/ready', ['Authorization' => 'Bearer s3cret'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['checks' => [['detail']]]]);
    }
}

class HealthRouteIpTest extends HealthRouteTestCase
{
    protected array $routeConfig = ['allowed_ips' => ['10.0.0.0/8']];

    public function test_ip_outside_the_list_is_forbidden(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('health/ready')->assertForbidden();
    }

    public function test_allowed_ip_shows_details(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->getJson('health/ready')
            ->assertOk()
            ->assertJsonStructure(['data' => ['checks' => [['detail']]]]);
    }
}
