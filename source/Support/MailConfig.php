<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Configuração SMTP (ADR 0060): credenciais vêm do ambiente (MOVESOS_SMTP_*), que vale sobre a
 * tabela settings. A tabela fica só como valor legado para o que o ambiente não definir, e a
 * senha nunca precisa morar no banco.
 */
final class MailConfig
{
    /** chave interna => [variável de ambiente, coluna legada em settings] */
    public const KEYS = [
        'host' => ['MOVESOS_SMTP_HOST', 'mail_host'],
        'port' => ['MOVESOS_SMTP_PORT', 'mail_port'],
        'user' => ['MOVESOS_SMTP_USER', 'mail_user'],
        'pass' => ['MOVESOS_SMTP_PASS', 'mail_pass'],
        'secure' => ['MOVESOS_SMTP_SECURE', 'mail_secure'],
        'auth' => ['MOVESOS_SMTP_AUTH', 'mail_auth'],
        'from_address' => ['MOVESOS_SMTP_FROM_ADDRESS', 'mail_address'],
        'from_name' => ['MOVESOS_SMTP_FROM_NAME', 'mail_name'],
    ];

    /**
     * @param callable(string): (string|false) $env  leitor do ambiente (getenv em produção)
     * @param object|array<string, mixed> $settings linha de settings (ou objeto com __get)
     * @return array{host:string, port:string, user:string, pass:string, secure:string, auth:mixed, from_address:string, from_name:string, source:array<string, string>}
     */
    public static function resolve(callable $env, object|array $settings): array
    {
        $config = ['source' => []];
        foreach (self::KEYS as $key => [$variable, $column]) {
            $value = $env($variable);
            if (is_string($value) && $value !== '') {
                $config[$key] = $key === 'auth' ? self::bool($value) : $value;
                $config['source'][$key] = 'env';
                continue;
            }
            $legacy = is_array($settings) ? ($settings[$column] ?? null) : ($settings->{$column} ?? null);
            $config[$key] = $key === 'auth' ? $legacy : (string)($legacy ?? '');
            $config['source'][$key] = 'settings';
        }
        return $config;
    }

    /** Configuração efetiva do processo atual. */
    public static function current(object|array $settings): array
    {
        return self::resolve(static fn (string $name): string|false => getenv($name), $settings);
    }

    private static function bool(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
