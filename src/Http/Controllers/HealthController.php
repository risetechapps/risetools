<?php

namespace RiseTechApps\RiseTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\RiseTools\Features\HealthCheck\Doctor;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Probes para orquestrador/load balancer (habilite em risetools.health.route).
 *
 * - live:  o processo responde. Não toca em banco/cache nem expõe nada.
 * - ready: roda os checks de risetools.health.route.checks; 200 ou 503.
 *
 * O detalhe de cada check (nome do banco, host...) só aparece para quem passa
 * pelo token ou pela lista de IPs. Sem nenhum dos dois configurado, a rota
 * responde só ok/falhou por check.
 */
class HealthController
{
    public function live(): JsonResponse
    {
        return response()->jsonBase(true, 'Alive.', ['status' => 'ok'], Response::HTTP_OK);
    }

    public function ready(Request $request, Doctor $doctor): JsonResponse
    {
        $access = $this->access($request);

        if ($access === false) {
            return response()->jsonForbidden();
        }

        $checks = array_values(array_intersect(
            (array) config('risetools.health.route.checks', ['database', 'cache']),
            $doctor->available()
        ));

        $results = $doctor->run($checks);
        $healthy = Doctor::healthy($results);

        if ($access === null) {
            $results = array_map(fn($result) => ['check' => $result['check'], 'ok' => $result['ok']], $results);
        }

        return response()->jsonBase(
            $healthy,
            $healthy ? 'Ready.' : 'Not ready.',
            ['checks' => $results],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    /**
     * true = autorizado (vê detalhes), false = negado, null = sem proteção
     * configurada (não vê detalhes).
     */
    protected function access(Request $request): ?bool
    {
        $token = (string) config('risetools.health.route.token', '');
        $ips = array_filter((array) config('risetools.health.route.allowed_ips', []));

        if ($token === '' && empty($ips)) {
            return null;
        }

        if ($token !== '') {
            $given = (string) ($request->header('X-Health-Token') ?: $request->bearerToken());

            if ($given !== '' && hash_equals($token, $given)) {
                return true;
            }
        }

        if (!empty($ips) && $request->ip() && IpUtils::checkIp($request->ip(), $ips)) {
            return true;
        }

        return false;
    }
}
