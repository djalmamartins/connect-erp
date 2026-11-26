<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Caixa de teste da fila de e-mail no laboratório (ADR 0656).
 *
 * Fora da produção, com ERP_MAIL_DELIVERY=sandbox e ERP_MAIL_SANDBOX_TO válido, todo e-mail da mail_queue
 * (avisos do ERP, chamados, propostas, código de 2FA, convites) vai só para a caixa de teste, com "[TESTE]"
 * no assunto. Assim ligar LAB_MAIL_QUEUE_SEND=1 no lab nunca manda e-mail para um endereço real gravado na
 * fila. Também aponta chaves do .env com nome parecido com uma chave conhecida (erro de digitação).
 */
final class MailSandbox
{
    public const PREFIX = '[TESTE] ';

    /** Chaves do .env que o envio de e-mail lê; usadas para apontar erro de digitação. */
    public const MAIL_KEYS = [
        'APP_ENV', 'ERP_MAIL_DELIVERY', 'ERP_MAIL_SANDBOX_TO', 'LAB_MAIL_QUEUE_SEND', 'MAIL_QUEUE_SEND',
        'MOVESOS_SMTP_HOST', 'MOVESOS_SMTP_PORT', 'MOVESOS_SMTP_USER', 'MOVESOS_SMTP_PASS', 'MOVESOS_SMTP_SECURE',
        'MOVESOS_SMTP_AUTH', 'MOVESOS_SMTP_FROM_ADDRESS', 'MOVESOS_SMTP_FROM_NAME', 'MAIL_DKIM_SELECTOR',
    ];

    /** Caixa de teste para onde a fila deve ir (regra pura); null = enviar ao destinatário gravado. */
    public static function target(string $appEnv, string $delivery, ?string $sandboxTo): ?string
    {
        if (strtolower(trim($appEnv)) === 'production' || strtolower(trim($delivery)) !== 'sandbox') {
            return null;
        }
        $sandboxTo = trim((string)$sandboxTo);
        return filter_var($sandboxTo, FILTER_VALIDATE_EMAIL) ? $sandboxTo : null;
    }

    /** Caixa de teste do processo atual. */
    public static function current(): ?string
    {
        return self::target(defined('CONF_APP_ENV') ? (string)CONF_APP_ENV : 'production',
            (string)(getenv('ERP_MAIL_DELIVERY') ?: ''), getenv('ERP_MAIL_SANDBOX_TO') ?: null);
    }

    /** Assunto com "[TESTE]" uma vez só (regra pura). */
    public static function subject(string $subject): string
    {
        return str_starts_with($subject, trim(self::PREFIX)) ? $subject : self::PREFIX . $subject;
    }

    /**
     * Chaves do .env que parecem erro de digitação de uma chave conhecida (regra pura).
     *
     * @param list<string> $keys  chaves do .env
     * @param list<string> $known chaves conhecidas
     * @return array<string, string> chave escrita => chave certa
     */
    public static function typos(array $keys, array $known = self::MAIL_KEYS): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = strtoupper(trim($key));
            if ($key === '' || in_array($key, $known, true)) {
                continue;
            }
            foreach ($known as $right) {
                if (levenshtein($key, $right) <= 2) {
                    $out[$key] = $right;
                    break;
                }
            }
        }
        return $out;
    }

    /** "ana@exemplo.com" → "a**@exemplo.com" (regra pura). */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('*', max(1, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }
}
