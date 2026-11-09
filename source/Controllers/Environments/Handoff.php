<?php

declare(strict_types=1);

namespace Source\Controllers\Environments;

use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\Session\AppLog;
use Source\Models\User;
use Source\Services\Environments;
use Source\Services\SessionRevocation;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Troca de ambiente do alternador da sidebar (ADR 0034).
 *
 * GET /ambiente/{environment}: no host de origem, com sessão completa (senha + 2FA), leva ao
 * ambiente. No mesmo host é só um redirecionamento; em outro host emite um código de uso único.
 *
 * GET /ambiente/entrar/{environment}/{token}: no host de destino, troca o código pela sessão.
 * O código vale 60 s, uma vez, só para o ambiente emitido e só no host desse ambiente.
 *
 * As duas rotas aceitam ?ir=/caminho (ADR 0142) para abrir uma página do ambiente, como o chamado
 * no Help Desk a partir do ERP. O caminho passa por Environments::safePath() nas duas pontas.
 */
final class Handoff
{
    public function go(array $data): void
    {
        $this->headers();
        $key = (string)($data['environment'] ?? '');
        if (!Environments::exists($key)) {
            $this->fail();
            return;
        }
        // Deep link (ADR 0142): ?ir=/caminho leva a uma página do ambiente, não só à inicial.
        $next = Environments::safePath($key, isset($_GET['ir']) ? (string)$_GET['ir'] : null);
        $target = Environments::target($key, null, $next);
        $user = Auth::user();
        if ($user === null) {
            // Sem sessão: o destino mostra o próprio login.
            redirect(Environments::absolute($target['host'], $target['path']));
            return;
        }
        if (!Environments::canAccess($user, $key)) {
            $this->fail();
            return;
        }
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($target['host'] === $host) {
            redirect(url($target['path']));
            return;
        }
        if (request_limit('environment_handoff', 30, 60)) {
            $this->fail();
            return;
        }
        try {
            $token = (new Environments())->issue($user, $key);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'environment_handoff_failed', 'users_id' => (int)$user->id]);
            redirect(Environments::absolute($target['host'], $target['path']));
            return;
        }
        redirect(Environments::absolute($target['host'], '/ambiente/entrar/' . $key . '/' . $token . ($next !== null ? '?ir=' . rawurlencode($next) : '')));
    }

    public function enter(array $data): void
    {
        $this->headers();
        $key = (string)($data['environment'] ?? '');
        $token = strtolower((string)($data['token'] ?? ''));
        if (!Environments::exists($key)) {
            $this->fail();
            return;
        }
        $next = Environments::safePath($key, isset($_GET['ir']) ? (string)$_GET['ir'] : null);
        $target = Environments::target($key, null, $next);
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($target['host'] !== $host) {
            // Código emitido para outro host: não abre sessão aqui.
            $this->fail();
            return;
        }
        $userId = null;
        try {
            $userId = (new Environments())->consume($token, $key);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'environment_handoff_failed']);
        }
        $user = $userId !== null ? (new User())->findById($userId) : null;
        if ($user === null || (string)($user->status ?? '') === 'trash') {
            AppLogger::log('notice', 'Troca de ambiente recusada', ['event_type' => 'environment_handoff_rejected', 'code' => 'HANDOFF_INVALID', 'status' => 'resolved'], 'authentication');
            redirect(url($target['path']));
            return;
        }
        $current = Auth::user();
        if ($current === null || (int)$current->id !== (int)$user->id) {
            Auth::cancelTwoFactor();
            (new Session())->regenerate()->set('authUser', (int)$user->id)->set(Auth::AUTH_AT, SessionRevocation::nowMs() + 1);
            (new AppLog())->register((int)$user->id, 'Entrou pelo alternador de ambientes: ' . $key);
            Audit::record('environment_handoff', 'users', (int)$user->id, [], ['environment' => $key]);
        }
        redirect(url($target['path']));
    }

    private function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, private');
        // O código vai na URL: não pode vazar no Referer da página seguinte.
        header('Referrer-Policy: no-referrer');
    }

    private function fail(): void
    {
        redirect(url('/'));
    }
}
