<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;

/**
 * Catálogo de adaptadores bancários (ADR 0035). Só existe o sandbox: um banco real entra aqui
 * depois de homologado, com credenciais no cofre e aprovação para produção (BANKING_INTEGRATIONS.md,
 * item 10). Nome desconhecido = InvalidArgumentException, nunca um adaptador padrão silencioso.
 */
final class BankAdapters
{
    public const DEFAULT = 'sandbox';

    /** @var array<string, BankAdapter> adaptadores trocados nos testes */
    private static array $overrides = [];

    public static function get(string $name = self::DEFAULT): BankAdapter
    {
        if (isset(self::$overrides[$name])) {
            return self::$overrides[$name];
        }
        return match ($name) {
            'sandbox' => new SandboxBankAdapter(),
            default => throw new InvalidArgumentException("Adaptador bancário '{$name}' não está disponível."),
        };
    }

    /** Testes: substitui um adaptador (ex.: um falso que simula timeout). null volta ao padrão. */
    public static function override(string $name, ?BankAdapter $adapter): void
    {
        if ($adapter === null) {
            unset(self::$overrides[$name]);
            return;
        }
        self::$overrides[$name] = $adapter;
    }
}
