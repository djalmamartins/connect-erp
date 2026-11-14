<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\MeterDeactivation as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Relógios parados inativados em lote (ADR 0299):
 *  POST /api/v1/meters/deactivate-idle {condominium_id, months?, meter_ids?, reason, dry_run?}
 * Exige erp.access, as permissões de ver e de gerenciar o cadastro de relógios (erp.meters.view e erp.meters.manage) e a
 * de ver leituras (erp.meter_readings.view), que entram na conferência; fail-closed. CSRF pelo ApiAuthenticated. Fora do
 * escopo = 404; entrada inválida ou relógio recusado = 422 (nada é inativado); relógio que muda de situação durante o
 * pedido = 409.
 */
final class MeterDeactivation
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $meters = (string)RegistryService::definition('meters')['permission'];
        $allowed = Access::can('erp.access', $user) && Access::can($meters . '.view', $user) && Access::can($meters . '.manage', $user)
            && Access::can('erp.meter_readings.view', $user);
        if (!$allowed) {
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
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Recusado: nenhum relógio foi inativado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
