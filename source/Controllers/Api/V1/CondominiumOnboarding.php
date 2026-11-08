<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\OnboardingChecklist;
use Source\Services\OnboardingItemNotFound;
use Source\Services\OnboardingValidationError;
use Source\Support\Access;

/**
 * /api/v1/condominiums/{id}/onboarding — checklist de chegada (ADR 0013).
 * Sessão e CSRF (X-CSRF-Token) validados pelo middleware ApiAuthenticated.
 */
final class CondominiumOnboarding
{
    public function index(array $data): void
    {
        $user = $this->authorize('erp.condominiums.view', $data);
        if ($user === null) {
            return;
        }
        $service = new OnboardingChecklist();
        $items = $service->items((int)$data['id']);
        JsonResponse::send(200, ['data' => ['items' => $items, 'summary' => $service->summary((int)$data['id'], $items)]]);
    }

    public function start(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage', $data);
        if ($user === null) {
            return;
        }
        $service = new OnboardingChecklist();
        $created = $service->start((int)$data['id'], (int)$user->id);
        JsonResponse::send($created > 0 ? 201 : 200, ['data' => ['created' => $created, 'summary' => $service->summary((int)$data['id'])]]);
    }

    public function add(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage', $data);
        if ($user === null) {
            return;
        }
        $body = self::body();
        try {
            $item = (new OnboardingChecklist())->addCustom((int)$data['id'], (string)($body['category'] ?? ''), (string)($body['title'] ?? ''), (int)$user->id);
        } catch (OnboardingValidationError $error) {
            self::invalid($error);
            return;
        }
        JsonResponse::send(201, ['data' => $item]);
    }

    public function update(array $data): void
    {
        $user = $this->authorize('erp.condominiums.manage', $data);
        if ($user === null) {
            return;
        }
        $itemId = (int)(filter_var($data['item'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $input = array_intersect_key(self::body(), array_flip(['status', 'due_date', 'expires_at', 'notes', 'responsible_user_id']));
        try {
            $item = (new OnboardingChecklist())->update((int)$data['id'], $itemId, $input, (int)$user->id);
        } catch (OnboardingItemNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Item não encontrado.']]);
            return;
        } catch (OnboardingValidationError $error) {
            self::invalid($error);
            return;
        }
        JsonResponse::send(200, ['data' => $item]);
    }

    /** Permissão da ação + escopo do condomínio (404 fora do escopo, ADR-002). */
    private function authorize(string $permission, array &$data): ?User
    {
        $user = Auth::user();
        if (!Access::can('erp.access', $user) || !Access::can($permission, $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        try {
            (new CondominiumScope())->assertAccess($user, $id);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado.']]);
            return null;
        }
        $data['id'] = $id;
        return $user;
    }

    /** @return array<string, mixed> corpo JSON (ou formulário) da requisição */
    private static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    private static function invalid(OnboardingValidationError $error): void
    {
        JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Dados inválidos.', 'fields' => $error->errors]]);
    }
}
