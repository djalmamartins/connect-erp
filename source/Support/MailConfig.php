<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Configuração SMTP (ADR 0060): credenciais vêm do ambiente (MOVESOS_SMTP_*), que vale sobre a
 * tabela settings. A tabela fica só como valor legado para o que o ambiente não definir, e a
 * senha nunca precisa morar no banco.
 *
 * Tela de Configurações do Studio (ADR 0600): quando o usuário e a senha SMTP vêm da tela, eles são
 * gravados cifrados (AES-256-GCM, chave CONNECT_2FA_KEY) com o prefixo "enc1:". Valor antigo sem o
 * prefixo continua lido como estava até a próxima gravação.
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

    /** Campos guardados cifrados na tabela settings. */
    public const SEALED = ['mail_user', 'mail_pass'];
    public const SEAL_PREFIX = 'enc1:';
    /** Bytes: senha/usuário maiores que isso não cabem cifrados na coluna varchar(255). */
    public const SECRET_MAX_LENGTH = 128;

    /** Cifra um valor para gravar em settings (coluna $column entra como contexto do AES-GCM). */
    public static function seal(string $plain, string $column, SecretBox $box): string
    {
        return self::SEAL_PREFIX . base64_encode($box->encrypt($plain, 'settings.' . $column));
    }

    public static function isSealed(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::SEAL_PREFIX);
    }

    /** Abre o valor gravado; null quando está cifrado e não abre (chave ausente ou trocada). */
    public static function open(?string $stored, string $column, ?SecretBox $box): ?string
    {
        $stored = (string)($stored ?? '');
        if (!self::isSealed($stored)) {
            return $stored;
        }
        if ($box === null) {
            return null;
        }
        $payload = base64_decode(substr($stored, strlen(self::SEAL_PREFIX)), true);
        if ($payload === false) {
            return null;
        }
        try {
            return $box->decrypt($payload, 'settings.' . $column);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param callable(string): (string|false) $env  leitor do ambiente (getenv em produção)
     * @param object|array<string, mixed> $settings linha de settings (ou objeto com __get)
     * @return array{host:string, port:string, user:string, pass:string, secure:string, auth:mixed, from_address:string, from_name:string, source:array<string, string>}
     */
    public static function resolve(callable $env, object|array $settings, ?SecretBox $box = null): array
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
            if (in_array($column, self::SEALED, true)) {
                $opened = self::open(is_scalar($legacy) ? (string)$legacy : null, $column, $box);
                $config[$key] = $opened ?? '';
                $config['source'][$key] = $opened === null ? 'unreadable' : 'settings';
                continue;
            }
            $config[$key] = $key === 'auth' ? $legacy : (string)($legacy ?? '');
            $config['source'][$key] = 'settings';
        }
        return $config;
    }

    /** Configuração efetiva do processo atual. */
    public static function current(object|array $settings): array
    {
        return self::resolve(static fn (string $name): string|false => getenv($name), $settings,
            SecretBox::isConfigured() ? new SecretBox() : null);
    }

    private static function bool(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
