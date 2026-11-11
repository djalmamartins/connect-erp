<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\BalanceSheet;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\LabSampleYear;

/**
 * ADR 0173 contra o MariaDB: o ano contábil de exemplo roda pelos services no schema real (o SQLite do unit não pega
 * diferenças de dialeto, como o ESCAPE do ADR 0172), é idempotente e o Balanço fecha e bate com a DRE e o Razão bancário.
 */
final class LabSampleYearIntegrationTest extends TestCase
{
    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        // O TRUNCATE do TestCase apaga o plano padrão; as contas patrimoniais 7/8/9 vêm da migration 0704 (idempotente).
        $this->pdo->exec((string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261109_0704_chart_patrimonial_natures.sql'));
        $userId = $this->createUser(['level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, (int)$this->pdo->lastInsertId()]);
        $this->developer = (new User())->findById($userId);
    }

    public function testYearRunsOnMariaDbIsIdempotentAndTheBalanceSheetCloses(): void
    {
        $today = date('Y-m-d');
        (new LabSampleData($this->pdo))->create($today);
        $first = (new LabSampleYear($this->pdo))->create($this->developer, $today);
        self::assertGreaterThan(0, $first['entries']);
        self::assertGreaterThan(0, $first['patrimonial']);
        self::assertSame(1, $first['agreements']);
        self::assertSame(1, $first['imports']);

        $second = (new LabSampleYear($this->pdo))->create($this->developer, $today);
        self::assertSame(0, array_sum($second), 'a segunda geração não pode criar nada');

        $condominium = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'EXEMPLO Residencial Aurora'")->fetchColumn();
        $sheet = (new BalanceSheet($this->pdo))->build($this->developer, $condominium, $today);
        self::assertTrue($sheet['balanced']);
        self::assertSame(0, (int)$sheet['diferenca']);
        self::assertSame($sheet['totals']['assets_cents'], $sheet['totals']['liabilities_and_equity_cents']);

        $plan = LabSampleYear::plan($today);
        $income = (new AccountingReports($this->pdo))->incomeStatement($this->developer, $condominium, $plan['start'], $today);
        self::assertGreaterThan(0, $income['totals']['revenue_cents']);

        $bank = (new AccountingReports($this->pdo))->bankLedger($this->developer, $condominium, $plan['start'], $today);
        self::assertSame($bank['totals']['opening_cents'] + $bank['totals']['debit_cents'] - $bank['totals']['credit_cents'], $bank['totals']['closing_cents']);
    }

    /** ADR 0175 no MariaDB: o --remover tira o ano e os cadastros na ordem das chaves, sem sobrar marca, e a geração seguinte recria tudo. */
    public function testRemoveTakesTheYearOutAndTheNextRunRecreatesIt(): void
    {
        $today = date('Y-m-d');
        $defaultAccounts = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts')->fetchColumn();
        (new LabSampleData($this->pdo))->create($today);
        $first = (new LabSampleYear($this->pdo))->create($this->developer, $today);

        $year = new LabSampleYear($this->pdo);
        $removed = (new LabSampleData($this->pdo))->remove(static fn (): array => $year->purge());
        self::assertSame(1, $removed['agreements']);
        self::assertSame(1, $removed['patrimonial_imports']);
        foreach (['erp_entries', 'erp_patrimonial_entries', 'erp_patrimonial_imports', 'erp_agreements', 'erp_bank_transfers', 'erp_chart_account_openings', 'condominiums'] as $table) {
            self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "{$table} ficou com registro de exemplo");
        }
        self::assertSame($defaultAccounts, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts')->fetchColumn(), 'o plano padrão não pode ser tocado');

        (new LabSampleData($this->pdo))->create($today);
        $again = (new LabSampleYear($this->pdo))->create($this->developer, $today);
        self::assertSame($first, $again);
    }
}
