<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\OnboardingRenewal as Service;
use Source\Support\Access;

/**
 * Renovação em lote dos itens do checklist de chegada (ADR 0219):
 *  POST /api/v1/condominiums/{id}/onboarding/renew {item_ids, renewed_on, expires_at?, dry_run?}
 * Exige erp.access e erp.condominiums.manage, as mesmas do PATCH de um item (fail-closed); CSRF pelo ApiAuthenticated.
 * Fora do escopo = 404; entrada inválida ou item recusado = 422 (nada é gravado).
 */
final class OnboardingRenewal
{
    public function store(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.condominiums.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $result = (new Service())->renew($user, $data['id'] ?? null, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Renovação recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
