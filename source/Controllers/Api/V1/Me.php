<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\CondominiumScope;
use Source\Services\TwoFactorService;
use Source\Support\Access;

/** GET /api/v1/me — usuário autenticado, papel, 2FA e condomínios permitidos. */
final class Me
{
    public function show(): void
    {
        $user = Auth::user();
        if (!$user) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $role = Access::role($user);
        $scope = new CondominiumScope();
        JsonResponse::send(200, ['data' => [
            'id' => (int)$user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'email' => (string)$user->email,
            'role' => $role ? ['slug' => (string)$role->slug, 'name' => (string)$role->name] : null,
            'two_factor_enabled' => (new TwoFactorService())->isEnabled((int)$user->id),
            'condominiums' => [
                'all' => $scope->hasGlobalAccess($user),
                'items' => $scope->allowed($user),
            ],
        ]]);
    }
}
