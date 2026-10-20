<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\Domain\Domain;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;
use RiseTechApps\RiseTools\RiseTools;
use RiseTechApps\RiseTools\RiseToolsFacade;
use RiseTechApps\RiseTools\Tests\TestCase;

class PackageBootTest extends TestCase
{
    public function test_facade_resolves(): void
    {
        $this->assertInstanceOf(RiseTools::class, RiseToolsFacade::getFacadeRoot());
    }

    public function test_facade_api(): void
    {
        $this->assertInstanceOf(Domain::class, \RiseTools::domain('app.example.com'));
        $this->assertInstanceOf(EmailValidator::class, \RiseTools::email());
        $this->assertInstanceOf(DatabaseSnapshot::class, \RiseTools::snapshot());
        $this->assertInstanceOf(DatabaseHealthMonitor::class, \RiseTools::dbHealth());
        $this->assertInstanceOf(NPlusOneDetector::class, \RiseTools::nPlusOne());
        $this->assertInstanceOf(\RiseTechApps\RiseTools\Features\HealthCheck\Doctor::class, \RiseTools::doctor());
        $this->assertSame('123.456.789-01', \RiseTools::mask()->cpf('12345678901'));
        $this->assertIsArray(\RiseTools::device(false));
        $this->assertSame('127.0.0.1', \RiseTools::clientIp());
        $this->assertSame(app(RiseTools::class), RiseToolsFacade::getFacadeRoot());

        if (extension_loaded('gd')) {
            $this->assertInstanceOf(AvatarGenerator::class, \RiseTools::avatar());
        }
    }

    public function test_config_is_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\RiseTechApps\RiseTools\RiseToolsServiceProvider::class, 'risetools-config');

        $this->assertCount(1, $paths);
        $this->assertSame(config_path('risetools.php'), array_values($paths)[0]);
        $this->assertFileExists(array_keys($paths)[0]);
    }

    public function test_config_is_merged_with_safe_defaults(): void
    {
        $this->assertFalse(config('risetools.geoip.enabled'));
        $this->assertFalse(config('risetools.ip.trust_cloudflare_header'));
        $this->assertSame('snapshots', config('risetools.snapshot.path'));
    }

    public function test_helpers_return_the_features(): void
    {
        $this->assertInstanceOf(EmailValidator::class, email_validator());
        $this->assertInstanceOf(NPlusOneDetector::class, n_plus_one_detector());
        $this->assertInstanceOf(DatabaseSnapshot::class, database_snapshot());
        $this->assertInstanceOf(DatabaseHealthMonitor::class, db_health_monitor());
        $this->assertSame('123.456', MaskInput('123456', '###.###'));

        if (extension_loaded('gd')) {
            $this->assertInstanceOf(AvatarGenerator::class, avatarGenerator());
        }
    }

    public function test_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        foreach (['risetools:doctor', 'risetools:db-health', 'risetools:snapshot-create', 'risetools:snapshot-restore', 'risetools:snapshot-list', 'risetools:snapshot-delete'] as $command) {
            $this->assertContains($command, $commands);
        }
    }
}
