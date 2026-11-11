<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VisitForecastExpiry as Service;
use Source\Support\Access;

/**
 * Baixa em lote das visitas previstas que passaram sem acontecer (ADR 0231):
 *  POST /api/v1/visit-forecasts/expire {condominium_id, before?, forecast_ids?, reason, dry_run?}
 * Exige erp.access, erp.visit_forecasts.view e erp.visit_forecasts.manage, as mesmas do cadastro (fail-closed); CSRF pelo
 * ApiAuthenticated. Fora do escopo = 404; entrada inválida ou visita recusada = 422 (nada é gravado). Nenhum aviso é
 * enviado.
 */
final class VisitForecastExpiry
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.visit_forecasts.view', $user) || !Access::can('erp.visit_forecasts.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->expire($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Baixa recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
