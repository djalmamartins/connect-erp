<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CostCenterDeactivation as Service;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Inativação em lote dos centros de custo parados (ADR 0273):
 *  POST /api/v1/cost-centers/deactivate-idle {condominium_id, cost_center_ids?, idle_months?, reason, dry_run?}
 * Exige erp.access, erp.cost_centers.view e erp.cost_centers.manage, as mesmas do cadastro, e erp.entries.view (o
 * movimento vem dos lançamentos); fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida ou
 * centro de custo recusado = 422 (nada é gravado). Nenhum aviso é enviado.
 */
final class CostCenterDeactivation
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.cost_centers.view', $user) || !Access::can('erp.cost_centers.manage', $user)
            || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->deactivate($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Inativação recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
