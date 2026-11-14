<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\UserAccessReport as Service;
use Source\Support\Access;

/**
 * Acessos dos usuários ao ERP por condomínio (ADR 0282), somente leitura:
 *  GET /api/v1/reports/user-access?condominium_id=
 * Exige erp.access e erp.users.view, as mesmas de Usuários do Sistema (AP-149), e o condomínio no escopo (fail-closed:
 * fora = 404). Entrada inválida = 422. Não grava nem envia nada; não toca login, 2FA nem o Studio.
 */
final class UserAccessReport
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.users.view', $user)) {
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
