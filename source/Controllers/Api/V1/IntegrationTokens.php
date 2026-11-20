<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;

/**
 * Tokens de acesso das integrações pela tela Configurações › Integrações (ADR 0623). Hoje só o Connect Talk.
 *  GET  /api/v1/integration-tokens               tokens (sem hash), escopos disponíveis e usuários que podem ser
 *                                                o usuário de integração (erp.settings.view)
 *  POST /api/v1/integration-tokens               {name, user_id, scopes[], expires_in_days?, allowed_ips?} → o token
 *                                                em claro UMA vez (erp.settings.manage)
 *  POST /api/v1/integration-tokens/{id}/revoke   {reason?} para na hora (erp.settings.manage)
 * Sessão e CSRF no ApiAuthenticated. Criar e revogar vão para a auditoria (TalkTokens).
 */
final class IntegrationTokens
{
    private const EXPIRY_DAYS = [0, 30, 90, 180, 365];

    public function index(): void
    {
        if ($this->authorize(false) === null) {
            return;
        }
        $tokens = new TalkTokens();
        $this->send(200, ['tokens' => $tokens->list(), 'scopes' => self::scopes(), 'users' => $tokens->eligibleUsers()]);
    }

    public function store(): void
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
        if (!is_array($body)) {
            $this->send(400, null, ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']);
            return;
        }
        $tokens = new TalkTokens();
        $userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $eligible = array_column($tokens->eligibleUsers(), 'id');
        if ($userId === false || !in_array((int)$userId, $eligible, true)) {
            $this->invalid('user_id', 'Escolha um usuário ativo com a permissão de atender chamados (support.manage).');
            return;
        }
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $this->invalid('name', 'Dê um nome de 3 a 80 caracteres (ex.: Connect Talk do escritório).');
            return;
        }
        $days = (int)($body['expires_in_days'] ?? 0);
        if (!in_array($days, self::EXPIRY_DAYS, true)) {
            $this->invalid('expires_in_days', 'Validade inválida.');
            return;
        }
        try {
            $scopes = TalkTokens::normalizeScopes($body['scopes'] ?? []);
            $created = $tokens->create((int)$userId, $name, (int)$user->id, $scopes,
                $days > 0 ? date('Y-m-d 23:59:59', strtotime('+' . $days . ' days')) : null, $body['allowed_ips'] ?? null);
        } catch (InvalidArgumentException $error) {
            $this->invalid('scopes', $error->getMessage());
            return;
        }
        $row = array_values(array_filter($tokens->list(), static fn (array $t): bool => $t['id'] === $created['id']))[0] ?? null;
        $this->send(201, ['token' => $created['token'], 'item' => $row]);
    }

    public function revoke(array $data): void
    {
        if ($this->authorize(true) === null) {
            return;
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $body = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
        $reason = trim(is_array($body) && is_string($body['reason'] ?? null) ? $body['reason'] : '');
        if ($id === false || !(new TalkTokens())->revoke((int)$id, $reason !== '' ? $reason : 'revogado na tela')) {
            $this->send(404, null, ['code' => 'not_found', 'message' => 'Token não encontrado ou já revogado.']);
            return;
        }
        $this->send(200, ['id' => (int)$id, 'revoked' => true]);
    }

    /** @return list<array{scope:string, label:string, kind:string}> */
    public static function scopes(): array
    {
        $out = [];
        foreach (TalkTokens::SCOPES as $scope => [$label, $kind]) {
            $out[] = ['scope' => $scope, 'label' => $label, 'kind' => $kind];
        }
        return $out;
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            $this->send(401, null, ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']);
            return null;
        }
        foreach (['erp.access', $manage ? 'erp.settings.manage' : 'erp.settings.view'] as $permission) {
            if (!Access::can($permission, $user)) {
                $this->send(403, null, ['code' => 'forbidden', 'message' => 'Sem permissão.']);
                return null;
            }
        }
        return $user;
    }

    private function invalid(string $field, string $message): void
    {
        $this->send(422, null, ['code' => 'validation_failed', 'message' => $message, 'fields' => [$field => $message]]);
    }

    private function send(int $status, mixed $data, ?array $error = null): void
    {
        header('Cache-Control: no-store, private');
        JsonResponse::send($status, $error !== null ? ['error' => $error] : ['data' => $data]);
    }
}
