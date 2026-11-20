<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Regras da tela de Configurações do Studio (ADR 0600): validação dos campos de sistema e
 * resumo do e-mail para a tela, sem nunca devolver usuário ou senha.
 */
final class SystemSettingsRules
{
    /** Regiões do Amazon SES (servidor SMTP email-smtp.REGIAO.amazonaws.com). */
    public const SES_REGIONS = [
        'sa-east-1' => 'São Paulo (sa-east-1)',
        'us-east-1' => 'Virgínia do Norte (us-east-1)',
        'us-east-2' => 'Ohio (us-east-2)',
        'us-west-2' => 'Oregon (us-west-2)',
        'eu-west-1' => 'Irlanda (eu-west-1)',
        'eu-central-1' => 'Frankfurt (eu-central-1)',
        'ap-south-1' => 'Mumbai (ap-south-1)',
    ];

    public static function sesHost(string $region): ?string
    {
        return array_key_exists($region, self::SES_REGIONS) ? "email-smtp.{$region}.amazonaws.com" : null;
    }

    /** Região do SES a partir do servidor salvo (para a tela marcar o provedor), ou null. */
    public static function sesRegion(string $host): ?string
    {
        return preg_match('~^email-smtp\.([a-z0-9-]+)\.amazonaws\.com$~i', trim($host), $match) ? strtolower($match[1]) : null;
    }

    /** Devolve a mensagem do primeiro campo inválido, ou null quando está tudo certo. */
    public static function validate(array $data): ?string
    {
        $value = static fn (string $key): string => trim((string)($data[$key] ?? ''));
        if (array_key_exists('site_domain_ssl', $data) && $value('site_domain_ssl') !== '') {
            $url = $value('site_domain_ssl');
            if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://') || parse_url($url, PHP_URL_HOST) === null) {
                return 'O domínio público precisa começar com https:// (ex.: https://www.connectcondominios.com.br).';
            }
        }
        if (array_key_exists('site_domain', $data) && $value('site_domain') !== ''
            && !preg_match('~^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$~i', $value('site_domain'))) {
            return 'O domínio sem https deve ser só o nome, por exemplo connectcondominios.com.br.';
        }
        if (array_key_exists('mail_host', $data) && $value('mail_host') !== ''
            && !preg_match('~^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$~i', $value('mail_host'))) {
            return 'Servidor SMTP inválido: informe só o endereço, por exemplo email-smtp.sa-east-1.amazonaws.com.';
        }
        if (array_key_exists('mail_port', $data) && $value('mail_port') !== '') {
            $port = filter_var($value('mail_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                return 'Porta SMTP inválida (use 587 para TLS ou 465 para SSL).';
            }
        }
        if (array_key_exists('mail_secure', $data) && !in_array($value('mail_secure'), ['', 'tls', 'ssl'], true)) {
            return 'Segurança do e-mail inválida.';
        }
        foreach (['mail_auth' => 'Autenticação', 'mail_html' => 'Formato HTML'] as $key => $label) {
            if (array_key_exists($key, $data) && !in_array($value($key), ['0', '1'], true)) {
                return "{$label} do e-mail inválido.";
            }
        }
        foreach (['mail_address' => 'E-mail do remetente', 'mail_suport' => 'E-mail de suporte'] as $key => $label) {
            if (array_key_exists($key, $data) && $value($key) !== '' && filter_var($value($key), FILTER_VALIDATE_EMAIL) === false) {
                return "{$label} inválido.";
            }
        }
        if (array_key_exists('mail_lang', $data) && $value('mail_lang') !== '' && !preg_match('~^[a-z]{2}(_[a-z]{2})?$~i', $value('mail_lang'))) {
            return 'Idioma das mensagens do e-mail inválido (ex.: br).';
        }
        return null;
    }

    /**
     * Resumo para a tela: de onde vem cada valor (tela ou .env), se usuário e senha estão guardados e
     * cifrados, e se faltam peças. Nunca devolve o usuário nem a senha.
     *
     * @param array{source: array<string, string>, host: string, from_address: string} $config saída de MailConfig::current
     */
    public static function mailStatus(object|array $settings, array $config, bool $keyConfigured): array
    {
        $raw = static fn (string $column): string => (string)((is_array($settings) ? ($settings[$column] ?? '') : ($settings->{$column} ?? '')) ?? '');
        $fromEnv = [];
        foreach (MailConfig::KEYS as $key => [, $column]) {
            if (($config['source'][$key] ?? '') === 'env') {
                $fromEnv[] = $column;
            }
        }
        $stored = [];
        foreach (MailConfig::SEALED as $column) {
            $value = $raw($column);
            $stored[$column] = $value === '' ? 'empty' : (MailConfig::isSealed($value) ? 'sealed' : 'plain');
        }
        $unreadable = [];
        foreach (['mail_user' => 'user', 'mail_pass' => 'pass'] as $column => $key) {
            if (($config['source'][$key] ?? '') === 'unreadable') {
                $unreadable[] = $column;
            }
        }
        return [
            'from_env' => $fromEnv,
            'stored' => $stored,
            'unreadable' => $unreadable,
            'key_configured' => $keyConfigured,
            'ready' => ($config['host'] ?? '') !== '' && filter_var((string)($config['from_address'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
            'ses_region' => self::sesRegion((string)($config['host'] ?? '')),
        ];
    }
}
