<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use Source\Services\Erp\Banking\Inter\CurlInterHttp;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;

/**
 * Catálogo de adaptadores bancários (ADR 0035 e 0156). O sandbox não precisa de credencial; o
 * Inter (ADR 0156, só sandbox do Inter) é montado por conta bancária com as credenciais do cofre
 * (forAccount). Nome desconhecido = InvalidArgumentException, nunca um adaptador padrão silencioso.
 */
final class BankAdapters
{
    public const DEFAULT = 'sandbox';
    public const NAMES = ['sandbox', InterBankAdapter::NAME];

    /** @var array<string, BankAdapter> adaptadores trocados nos testes */
    private static array $overrides = [];

    public static function get(string $name = self::DEFAULT): BankAdapter
    {
        if (isset(self::$overrides[$name])) {
            return self::$overrides[$name];
        }
        return match ($name) {
            'sandbox' => new SandboxBankAdapter(),
            InterBankAdapter::NAME => throw new InvalidArgumentException('O adaptador do Inter depende da conta bancária (use forAccount).'),
            default => throw new InvalidArgumentException("Adaptador bancário '{$name}' não está disponível."),
        };
    }

    /**
     * Adaptador da conta bancária (coluna erp_bank_accounts.adapter). Conta do Inter sem credencial
     * no cofre = RuntimeException (nunca cai no sandbox sem avisar).
     */
    public static function forAccount(array $account, ?PDO $pdo = null): BankAdapter
    {
        $name = (string)($account['adapter'] ?? self::DEFAULT);
        if (isset(self::$overrides[$name]) || $name === self::DEFAULT) {
            return self::get($name);
        }
        if ($name === InterBankAdapter::NAME) {
            $secrets = (new BankCredentials($pdo))->secrets((int)$account['id']);
            return new InterBankAdapter($secrets + ['account_number' => (string)($account['account_number'] ?? '')], new CurlInterHttp());
        }
        throw new InvalidArgumentException("Adaptador bancário '{$name}' não está disponível.");
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
