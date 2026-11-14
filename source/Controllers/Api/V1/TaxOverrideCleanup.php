<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\TaxOverrideCleanup as Service;
use Source\Support\Access;

/**
 * Alíquotas próprias que só repetem o padrão inativadas em lote (ADR 0293):
 *  POST /api/v1/taxes/deactivate-redundant {condominium_id, kinds?, reason, dry_run?}
 * Exige erp.access e as permissões de ver e de gerenciar o cadastro de impostos (erp.settings.view e
 * erp.settings.manage, as da tela AP-164); fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada
 * inválida ou imposto recusado = 422 (nada é inativado); alíquota que muda durante o pedido = 409.
 */
final class TaxOverrideCleanup
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $permission = (string)RegistryService::definition('taxes')['permission'];
        if (!Access::can('erp.access', $user) || !Access::can($permission . '.view', $user) || !Access::can($permission . '.manage', $user)) {
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
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Recusado: nenhuma alíquota foi inativada.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
