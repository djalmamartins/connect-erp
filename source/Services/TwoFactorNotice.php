<?php

declare(strict_types=1);

namespace Source\Services;

use Source\Support\AppLogger;
use Source\Support\Email;
use Source\Support\MailTemplate;

/**
 * Avisa o dono da conta por e-mail quando o 2FA muda (issue #7, risco 1; ADR 0028).
 * Mitiga o cadastro do autenticador por quem só tem a senha: o dono fica sabendo.
 * Vai pela fila mail_queue (sem SMTP durante o login); falha nunca interrompe o fluxo, fica no log.
 */
final class TwoFactorNotice
{
    public const ENABLED = 'enabled';
    public const ENABLED_AT_LOGIN = 'enabled_at_login';
    public const DISABLED = 'disabled';
    public const RESET_BY_ADMIN = 'reset_by_admin';

    private const CHANGES = [
        self::ENABLED => 'ativada',
        self::ENABLED_AT_LOGIN => 'ativada durante o login',
        self::DISABLED => 'desativada',
        self::RESET_BY_ADMIN => 'redefinida por um administrador',
    ];

    /** @param callable(string $subject, string $html, string $email, string $name):bool|null $sender */
    public function __construct(private ?MailTemplate $templates = null, private $sender = null)
    {
    }

    /** HTML do aviso; separado do envio para teste. */
    public function render(string $firstName, string $event, ?int $now = null): string
    {
        if (!isset(self::CHANGES[$event])) {
            throw new \InvalidArgumentException('Evento de 2FA desconhecido.');
        }
        return ($this->templates ?? new MailTemplate())->render('two-factor-changed', [
            'first_name' => $firstName,
            'change' => self::CHANGES[$event],
            'when' => date('d/m/Y \à\s H:i', $now ?? time()),
        ]);
    }

    public function send(object $user, string $event): bool
    {
        $email = (string)($user->email ?? '');
        if ($email === '') {
            return false;
        }
        try {
            $html = $this->render((string)($user->first_name ?? ''), $event);
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            $subject = 'Autenticação em dois fatores alterada';
            $sent = $this->sender !== null
                ? (bool)($this->sender)($subject, $html, $email, $name)
                : (new Email())->bootstrap($subject, $html, $email, $name)->queue(userId: isset($user->id) ? (int)$user->id : null);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'two_factor_notice_failed', 'users_id' => $user->id ?? null]);
            return false;
        }
        if (!$sent) {
            AppLogger::log('warning', 'Aviso de 2FA não enfileirado', [
                'event_type' => 'two_factor_notice_failed', 'code' => 'TWO_FACTOR_NOTICE_FAILED', 'users_id' => $user->id ?? null, 'status' => 'open',
            ], 'authentication');
        }
        return $sent;
    }
}
