<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Services\TwoFactorService;
use Source\Support\Access;
use Source\Support\SecretBox;

/**
 * Segurança da conta dentro do ERP: o usuário ativa, desativa e renova os códigos de
 * recuperação do próprio 2FA sem depender do Studio (que exige studio.access).
 *
 * Mesmas regras de Studio\TwoFactor::manage: ações sobre um 2FA ativo pedem o código
 * atual (reautenticação), 2FA obrigatório não pode ser desativado, limite de tentativas
 * e CSRF. Formulários comuns com POST/redirect/GET (o ERP não usa jQuery).
 */
final class Security extends Controller
{
    private const CODES_KEY = 'auth2faRecoveryCodes';

    /** GET /seguranca */
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        $panel = new Panel();
        if (!Access::can('erp.access', $user)) {
            $panel->routeError(403);
            return;
        }
        $twoFactor = new TwoFactorService();
        $session = new Session();
        $enabled = $twoFactor->isEnabled((int)$user->id);
        $setup = null;
        if (!$enabled && SecretBox::isConfigured()) {
            $secret = $twoFactor->pendingSecret((int)$user->id) ?? $twoFactor->beginSetup((int)$user->id);
            $setup = ['secret' => $secret, 'uri' => $twoFactor->provisioningUri((int)$user->id, $secret, (string)$user->email)];
        }
        $codes = [];
        if ($session->has(self::CODES_KEY)) {
            $codes = array_map('strval', (array)$session->{self::CODES_KEY});
            $session->unset(self::CODES_KEY);
        }
        header('Cache-Control: no-store, private');
        $panel->render('pages/auth/security', $user, 'Segurança da conta', [
            'twoFactorEnabled' => $enabled,
            'twoFactorConfigured' => SecretBox::isConfigured(),
            'twoFactorEnforced' => TwoFactorService::isEnforced(),
            'twoFactorSetup' => $setup,
            'recoveryCodes' => $codes,
            'recoveryRemaining' => $enabled ? $twoFactor->remainingRecoveryCodes((int)$user->id) : 0,
        ]);
    }

    /** POST /seguranca — action: confirm (ativar), recovery (novos códigos) ou disable. */
    public function update(?array $data): void
    {
        $user = Auth::user();
        if ($user === null || !Access::can('erp.access', $user)) {
            redirect(erp_url('/login'));
            return;
        }
        $back = erp_url('/seguranca');
        if (!csrf_verify($data ?? [])) {
            $this->message->error('Sessão expirada. Atualize a página e tente novamente.')->flash();
            redirect($back);
            return;
        }
        if (request_limit('erp_2fa_manage', 10, 300)) {
            $this->message->warning('Muitas tentativas. Aguarde alguns minutos.')->flash();
            redirect($back);
            return;
        }
        $twoFactor = new TwoFactorService();
        $session = new Session();
        $userId = (int)$user->id;
        $action = (string)($data['action'] ?? '');
        $code = preg_replace('/\s+/', '', (string)($data['code'] ?? '')) ?? '';
        $enabled = $twoFactor->isEnabled($userId);

        if ($action === 'confirm' && !$enabled) {
            $codes = $twoFactor->confirm($userId, $code);
            if ($codes === null) {
                $this->message->error('Código de verificação inválido.')->flash();
            } else {
                $session->set(self::CODES_KEY, $codes);
                $this->message->success('Verificação em duas etapas ativada.')->flash();
            }
            redirect($back);
            return;
        }
        if ($enabled && in_array($action, ['recovery', 'disable'], true)) {
            if (!$twoFactor->verify($userId, $code)) {
                $this->message->error('Código de verificação inválido.')->flash();
                redirect($back);
                return;
            }
            if ($action === 'disable') {
                if (TwoFactorService::isEnforced()) {
                    $this->message->warning('A verificação em duas etapas é obrigatória e não pode ser desativada.')->flash();
                } else {
                    $twoFactor->disable($userId, $userId);
                    $this->message->success('Verificação em duas etapas desativada.')->flash();
                }
                redirect($back);
                return;
            }
            $session->set(self::CODES_KEY, $twoFactor->regenerateRecoveryCodes($userId));
            $this->message->success('Novos códigos de recuperação gerados. Os anteriores deixaram de valer.')->flash();
            redirect($back);
            return;
        }
        $this->message->warning('Ação não reconhecida.')->flash();
        redirect($back);
    }
}
