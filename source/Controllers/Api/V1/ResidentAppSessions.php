<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Services\Resident\ResidentAppSessions as Sessions;
use Source\Support\AppLogger;

/**
 * Sessão do app nativo do morador (ADR 0303), sem cookie nem CSRF: o app guarda o token Bearer no cofre do aparelho.
 *  POST   /api/v1/resident/app-sessions          {login, password, device_name?} → 201 {token, expires_at, idle_days}
 *                                                 ou 202 {two_factor_required: true, challenge}
 *  POST   /api/v1/resident/app-sessions/verify   {challenge, code} → 201 {token, expires_at, idle_days}
 *  DELETE /api/v1/resident/app-sessions/current  (Bearer) sai deste aparelho
 * Erros: 400 invalid_json, 401 invalid_credentials/challenge_expired/unauthenticated, 403 not_resident,
 * 409 two_factor_setup_required, 422 invalid_code, 429 too_many_attempts. Corpo até 8 KB.
 */
final class ResidentAppSessions
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
        $this->run(fn () => (new Sessions())->login($login, $password, $body['device_name'] ?? null));
    }

    public function verify(): void
    {
        $body = $this->body();
        if ($body !== null) {
            $this->run(fn () => (new Sessions())->verify($body['challenge'] ?? null, $body['code'] ?? null));
        }
    }

    public function destroy(): void
    {
        header('Cache-Control: no-store, private');
        $token = Sessions::requestBearer();
        if ($token === null || !(new Sessions())->revoke($token)) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        JsonResponse::send(200, ['data' => ['revoked' => true]]);
    }

    /** @param callable():array{status:int, body:array<string,mixed>} $action */
    private function run(callable $action): void
    {
        header('Cache-Control: no-store, private');
        try {
            $result = $action();
            JsonResponse::send($result['status'], $result['body']);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'resident_app_session_failed']);
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
