<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BannerOverview as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;

/**
 * Situação dos banners do aplicativo para o condomínio (ADR 0286), somente leitura:
 *  GET /api/v1/reports/banners?condominium_id=
 * Exige erp.access e a permissão de ver do cadastro de banners (erp.settings.view, a da tela AP-156), e o condomínio no
 * escopo (fail-closed: fora = 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class BannerOverview
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $permission = (string)RegistryService::definition('banners')['permission'] . '.view';
        if (!Access::can('erp.access', $user) || !Access::can($permission, $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
