<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\PaymentLocks;

/**
 * ADR 0193: travas de estorno ligadas ao banco, compartilhadas entre o estorno em lote (ADR 0192) e o formulário.
 * Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2); o formulário é conferido no MariaDB por
 * Integration\EntryReversalLocksTest.
 */
final class PaymentLocksTest extends TestCase
{
    public function testFindsReconciledAndBankConfirmedEntries(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE erp_bank_statement_lines (id INTEGER PRIMARY KEY, status TEXT, entry_id INTEGER)');
        $pdo->exec('CREATE TABLE erp_bank_charges (id INTEGER PRIMARY KEY, status TEXT, entry_id INTEGER)');
        $pdo->exec("INSERT INTO erp_bank_statement_lines (status, entry_id) VALUES ('matched', 1), ('matched', 3), ('ignored', 4), ('unmatched', NULL)");
        $pdo->exec("INSERT INTO erp_bank_charges (status, entry_id) VALUES ('paid', 2), ('paid', 3), ('cancelled', 5), ('registered', 6)");

        $locks = PaymentLocks::find($pdo, [1, 2, 3, 4, 5, 6, 7, '2']);
        ksort($locks);
        self::assertSame([1 => 'reconciled', 2 => 'bank_confirmed', 3 => 'reconciled'], array_map(static fn (array $l): string => $l['code'], $locks),
            'Conciliação vem primeiro; linha ignorada, boleto cancelado ou só registrado não travam.');
        self::assertSame(PaymentLocks::RECONCILED, $locks[1]['message']);
        self::assertSame(PaymentLocks::BANK_CONFIRMED, $locks[2]['message']);
        self::assertSame([], PaymentLocks::find($pdo, []));
    }

    public function testFormAndBatchUseTheSameRule(): void
    {
        $root = dirname(__DIR__, 3);
        $registry = (string)file_get_contents($root . '/source/Services/Erp/Registry/RegistryService.php');
        foreach (["\\Source\\Services\\Erp\\PaymentLocks::find(\$this->pdo, [(int)\$id])", "(\$definition['table'] ?? '') === 'erp_entries'",
            "(\$before['paid_on'] ?? null) !== null", "(\$values['paid_on'] ?? null) === null", "\$errors['paid_on'] = \$lock['message'];"] as $needle) {
            self::assertStringContainsString($needle, $registry, $needle);
        }
        $batch = (string)file_get_contents($root . '/source/Services/Erp/BatchReversal.php');
        self::assertStringContainsString('PaymentLocks::find($this->pdo, $ids)', $batch);
        self::assertStringNotContainsString('FROM erp_bank_statement_lines', $batch, 'A consulta mora só no PaymentLocks.');
    }
}
