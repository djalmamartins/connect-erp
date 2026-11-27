<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Resident\ResidentAppSessions as Sessions;
use Source\Services\Team\TeamAppSessions;
use Source\Services\Team\TeamPush;
use Source\Services\TwoFactorService;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Apps da equipe no celular, SAC e Meu Dia (ADR 0660). Sessão por token Bearer, sem cookie nem CSRF; o app guarda o
 * token no cofre do aparelho e o envia em "Authorization: Bearer" em toda a API /api/v1 da equipe (ApiAuthenticated).
 *  POST   /api/v1/team/app-sessions          {login, password, device_name?} → 201 {token, expires_at, idle_days}
 *                                             ou 202 {two_factor_required: true, challenge}
 *  POST   /api/v1/team/app-sessions/verify   {challenge, code} → 201 {token, expires_at, idle_days}
 *  DELETE /api/v1/team/app-sessions/current  (Bearer) sai deste aparelho
 *  GET    /api/v1/team/me                    quem entrou e o que pode fazer nos apps
 *  POST   /api/v1/team/push-devices          {token, app: "sac"|"meu-dia", platform?} grava o celular para o push
 *  DELETE /api/v1/team/push-devices          {token, app} ou ?token=&app= o celular deixa de receber o push daquele app
 * Erros: 400 invalid_json, 401 invalid_credentials/challenge_expired/unauthenticated, 403 not_team,
 * 409 two_factor_setup_required, 422 invalid_code/validation, 429 too_many_attempts. Corpo até 8 KB.
 */
final class TeamApp
{
    private const MAX_BODY = 8192;

    public function store(): void
    {
        $body = $this->body();
        if ($body === null) {
            return;
        }
        $login = is_string($body['login'] ?? null) ? trim($body['login']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($login === '' || $password === '' || mb_strlen($login) > 191 || strlen($password) > 1024) {
            JsonResponse::send(422, ['error' => ['code' => 'validation', 'message' => 'Informe o e-mail (ou CPF) e a senha.']]);
            return;
        }
        $this->run(fn () => TeamAppSessions::make()->login($login, $password, $body['device_name'] ?? null));
    }

    public function verify(): void
    {
        $body = $this->body();
        if ($body !== null) {
            $this->run(fn () => TeamAppSessions::make()->verify($body['challenge'] ?? null, $body['code'] ?? null));
        }
    }

    public function destroy(): void
    {
        header('Cache-Control: no-store, private');
        $token = Sessions::requestBearer();
        if ($token === null || !TeamAppSessions::make()->revoke($token)) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        JsonResponse::send(200, ['data' => ['revoked' => true]]);
    }

    public function me(): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        $role = Access::role($user);
        JsonResponse::send(200, ['data' => [
            'id' => (int)$user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'first_name' => (string)($user->first_name ?? ''),
            'email' => (string)$user->email,
            'role' => $role ? ['slug' => (string)$role->slug, 'name' => (string)$role->name] : null,
            'two_factor_enabled' => (new TwoFactorService())->isEnabled((int)$user->id),
            'can_see_team' => Access::can('erp.myday.team', $user),
            'can_attend_sac' => Access::can('support.manage', $user),
            'can_inspect' => Access::can('erp.inspections.perform', $user),
        ]]);
    }

    public function registerPush(): void
    {
        $this->push(function (int $userId, array $body): void {
            (new TeamPush())->register($userId, (string)($body['app'] ?? ''), (string)($body['token'] ?? ''), (string)($body['platform'] ?? ''));
        }, 201);
    }

    public function forgetPush(): void
    {
        $this->push(function (int $userId, array $body): void {
            if (!TeamPush::validApp($body['app'] ?? null)) {
                throw new \InvalidArgumentException('App inválido.');
            }
            (new TeamPush())->forget($userId, (string)$body['app'], (string)($body['token'] ?? ''));
        }, 200);
    }

    /** @param callable(int, array<string,mixed>):void $action */
    private function push(callable $action, int $status): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        // DELETE sem corpo (alguns clientes não mandam): token e app também valem pela query.
        $raw = (string)file_get_contents('php://input', false, null, 0, 16);
        $body = trim($raw) === '' && isset($_GET['token']) ? ['token' => (string)$_GET['token'], 'app' => (string)($_GET['app'] ?? '')] : $this->body();
        if ($body === null) {
            return;
        }
        try {
            $action((int)$user->id, $body);
            JsonResponse::send($status, ['data' => ['ok' => true]]);
        } catch (\InvalidArgumentException $error) {
            JsonResponse::send(422, ['error' => ['code' => 'validation', 'message' => $error->getMessage()]]);
        }
    }

    private function user(): ?\Source\Models\User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem acesso ao ERP.']]);
            return null;
        }
        return $user;
    }

    /** @param callable():array{status:int, body:array<string,mixed>} $action */
    private function run(callable $action): void
    {
        header('Cache-Control: no-store, private');
        try {
            $result = $action();
            JsonResponse::send($result['status'], $result['body']);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'team_app_session_failed']);
            JsonResponse::send(500, ['error' => ['code' => 'server_error', 'message' => 'Não foi possível entrar agora. Tente de novo em instantes.']]);
        }
    }

    /** @return array<string,mixed>|null */
    private function body(): ?array
    {
        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            JsonResponse::send(413, ['error' => ['code' => 'payload_too_large', 'message' => 'Pedido grande demais.']]);
            return null;
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return null;
        }
        return $body;
    }
}
