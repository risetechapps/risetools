<?php

namespace RiseTechApps\RiseTools;

use RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\Device\Device;
use RiseTechApps\RiseTools\Features\Domain\Domain;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
use RiseTechApps\RiseTools\Features\HealthCheck\Doctor;
use RiseTechApps\RiseTools\Features\MaskInput\MaskInput;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;

/**
 * Porta de entrada única das features (via facade RiseTools ou app(RiseTools::class)).
 *
 *     RiseTools::avatar()->generateSvg('Ana Souza');
 *     RiseTools::domain('app.example.com')->getInfo(3600);
 *     RiseTools::email()->isDisposable($email);
 *     RiseTools::mask()->cpf('12345678901');
 */
class RiseTools
{
    public function avatar(): AvatarGenerator
    {
        return app(AvatarGenerator::class);
    }

    public function domain(string $domain): Domain
    {
        return new Domain($domain);
    }

    public function email(): EmailValidator
    {
        return app(EmailValidator::class);
    }

    /**
     * Dispositivo do request corrente (ver Device::info()).
     */
    public function device(bool $withGeoIp = true): array
    {
        return Device::info($withGeoIp);
    }

    public function clientIp(): ?string
    {
        return Device::getClientPublicIp();
    }

    public function mask(): MaskInput
    {
        return app(MaskInput::class);
    }

    public function snapshot(): DatabaseSnapshot
    {
        return app(DatabaseSnapshot::class);
    }

    public function dbHealth(): DatabaseHealthMonitor
    {
        return app(DatabaseHealthMonitor::class);
    }

    public function nPlusOne(): NPlusOneDetector
    {
        return app(NPlusOneDetector::class);
    }

    public function doctor(): Doctor
    {
        return app(Doctor::class);
    }
}
