<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ObligationCalendar as Service;
use Source\Support\Access;

/**
 * Calendário de vencimentos do condomínio (ADR 0218), somente leitura:
 *  GET /api/v1/reports/obligation-calendar?condominium_id=&as_of=AAAA-MM-DD&days=30|60|90
 * Exige erp.access e ao menos uma fonte permitida; cada fonte só entra com a permissão de ver o cadastro dela
 * (fail-closed; as demais vêm em hidden_sources). Fora do escopo = 404; entrada inválida = 422. Não grava nem envia nada.
 */
final class ObligationCalendar
{
    public const PERMISSIONS = ['insurances' => 'erp.insurances.view', 'contracts' => 'erp.contracts.view', 'board' => 'erp.board.view',
        'checklist' => 'erp.condominiums.view'];

    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $sources = [];
        foreach (self::PERMISSIONS as $source => $permission) {
            if (Access::can($permission, $user)) {
                $sources[] = $source;
            }
        }
        if (!Access::can('erp.access', $user) || $sources === []) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $sources, $_GET['as_of'] ?? null, $_GET['days'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
