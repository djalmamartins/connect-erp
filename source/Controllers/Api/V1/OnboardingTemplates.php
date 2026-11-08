<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\OnboardingCatalog;
use Source\Services\OnboardingItemNotFound;
use Source\Services\OnboardingValidationError;
use Source\Support\Access;

/** /api/v1/onboarding/templates — catálogo do checklist de chegada (ADR 0013). */
final class OnboardingTemplates
{
    private const FIELDS = ['code', 'category', 'title', 'description', 'default_due_days', 'renewal_months', 'position', 'active'];

    public function index(): void
    {
        if (!$this->allowed()) {
            return;
        }
        JsonResponse::send(200, ['data' => (new OnboardingCatalog())->all()]);
    }

    public function create(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $input = array_intersect_key(self::body(), array_flip(self::FIELDS)) + ['active' => true];
        try {
            JsonResponse::send(201, ['data' => (new OnboardingCatalog())->create($input)]);
        } catch (OnboardingValidationError $error) {
            self::invalid($error);
        }
    }

    public function update(array $data): void
    {
        if (!$this->allowed()) {
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $input = array_intersect_key(self::body(), array_flip(self::FIELDS));
        unset($input['code']);
        try {
            JsonResponse::send(200, ['data' => (new OnboardingCatalog())->update($id, $input)]);
        } catch (OnboardingItemNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Item do catálogo não encontrado.']]);
        } catch (OnboardingValidationError $error) {
            self::invalid($error);
        }
    }

    private function allowed(): bool
    {
        $user = Auth::user();
        if (!Access::can('erp.access', $user) || !Access::can('erp.onboarding.catalog', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $_POST;
    }

    private static function invalid(OnboardingValidationError $error): void
    {
        JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Dados inválidos.', 'fields' => $error->errors]]);
    }
}
