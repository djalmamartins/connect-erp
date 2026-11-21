<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use Source\Services\Erp\Banking\Cora\CoraBankAdapter;
use Source\Services\Erp\Banking\Inter\CurlInterHttp;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;
use Source\Services\Erp\Banking\Sicoob\SicoobBankAdapter;

/**
 * Catálogo de adaptadores bancários (ADR 0035 e 0156). O sandbox não precisa de credencial; o
 * Inter (ADR 0156, só sandbox do Inter), a Cora (ADR 0322, só stage) e o Sicoob (ADR 0633, só sandbox) são montados por conta bancária com as credenciais do cofre
 * (forAccount). Nome desconhecido = InvalidArgumentException, nunca um adaptador padrão silencioso.
 */
final class BankAdapters
{
    public const DEFAULT = 'sandbox';
    public const NAMES = ['sandbox', InterBankAdapter::NAME, CoraBankAdapter::NAME, SicoobBankAdapter::NAME];
    /** Adaptadores de banco de verdade (credencial no cofre, consulta automática, extrato). */
    public const REAL = [InterBankAdapter::NAME, CoraBankAdapter::NAME, SicoobBankAdapter::NAME];

    /** @var array<string, BankAdapter> adaptadores trocados nos testes */
    private static array $overrides = [];

    public static function get(string $name = self::DEFAULT): BankAdapter
    {
        if (isset(self::$overrides[$name])) {
            return self::$overrides[$name];
        }
        return match ($name) {
            'sandbox' => new SandboxBankAdapter(),
            InterBankAdapter::NAME, CoraBankAdapter::NAME, SicoobBankAdapter::NAME => throw new InvalidArgumentException('O adaptador do banco depende da conta bancária (use forAccount).'),
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
        if ($name === CoraBankAdapter::NAME) {
            // Cora (ADR 0322): só o ambiente de teste (stage).
            $secrets = (new BankCredentials($pdo))->secrets((int)$account['id']);
            return new CoraBankAdapter($secrets + ['account_number' => (string)($account['account_number'] ?? '')], new CurlInterHttp());
        }
        if ($name === SicoobBankAdapter::NAME) {
            // Sicoob (ADR 0633): só o sandbox. A modalidade de cobrança sai da carteira da conta (padrão 1).
            $secrets = (new BankCredentials($pdo))->secrets((int)$account['id']);
            return new SicoobBankAdapter($secrets + ['account_number' => (string)($account['account_number'] ?? ''),
                'modality' => (string)SicoobBankAdapter::modality($account['wallet'] ?? null)], new CurlInterHttp());
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
