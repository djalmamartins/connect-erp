<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\GatehouseOverview as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;

/**
 * Situação das portarias do condomínio (ADR 0294), somente leitura:
 *  GET /api/v1/reports/gatehouses?condominium_id=&days=
 * Exige erp.access e a permissão de ver de cada cadastro que entra na conta (portarias, acessos e encomendas:
 * erp.gatehouses.view, erp.access_logs.view e erp.deliveries.view), e o condomínio no escopo (fail-closed: fora = 404).
 * Entrada inválida = 422. Não grava nem envia nada.
 */
final class GatehouseOverview
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $allowed = Access::can('erp.access', $user);
        foreach (['gatehouses', 'access_logs', 'deliveries'] as $slug) {
            $allowed = $allowed && Access::can((string)RegistryService::definition($slug)['permission'] . '.view', $user);
        }
        if (!$allowed) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['days'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
