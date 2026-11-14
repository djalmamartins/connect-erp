<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\GatehouseDeactivation as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Portarias paradas inativadas em lote (ADR 0295):
 *  POST /api/v1/gatehouses/deactivate-idle {condominium_id, days?, gatehouse_ids?, reason, dry_run?}
 * Exige erp.access, as permissões de ver e de gerenciar o cadastro de portarias (erp.gatehouses.view e
 * erp.gatehouses.manage) e as de ver acessos e encomendas (erp.access_logs.view e erp.deliveries.view), que entram na
 * conferência; fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida ou portaria recusada = 422
 * (nada é inativado); portaria que muda de situação durante o pedido = 409.
 */
final class GatehouseDeactivation
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $gatehouses = (string)RegistryService::definition('gatehouses')['permission'];
        $allowed = Access::can('erp.access', $user) && Access::can($gatehouses . '.view', $user) && Access::can($gatehouses . '.manage', $user);
        foreach (['access_logs', 'deliveries'] as $slug) {
            $allowed = $allowed && Access::can((string)RegistryService::definition($slug)['permission'] . '.view', $user);
        }
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
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Recusado: nenhuma portaria foi inativada.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
