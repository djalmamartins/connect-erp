<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\SupplierDeactivation as Service;
use Source\Support\Access;

/**
 * Inativação de fornecedores em lote (ADR 0202):
 *  POST /api/v1/suppliers/deactivate {supplier_ids[], reason, dry_run?}
 * Exige erp.access, erp.suppliers.view e erp.suppliers.manage, e acesso a todos os condomínios (cadastro comum; sem ele,
 * 403); CSRF pelo ApiAuthenticated. Entrada inválida ou fornecedor recusado = 422 (errors e _supplier; nada é gravado).
 */
final class SupplierDeactivation
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.suppliers.view', $user) || !Access::can('erp.suppliers.manage', $user)) {
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
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Fornecedor recusado: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryForbidden $exception) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => $exception->getMessage()]]);
        }
    }
}
