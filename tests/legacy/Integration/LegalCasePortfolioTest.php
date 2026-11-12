<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LegalCasePortfolio;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0250: carteira da cobrança judicial com processos e contas do cadastro; somente leitura e sem dado do processo. */
final class LegalCasePortfolioTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $bank;
    private int $chartAccount;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->chartAccount = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica',
            'name' => 'Cotas'])['id'];
        foreach (['101', '102', '103'] as $number) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testPortfolioFromTheCases(): void
    {
        $this->pdo->exec("INSERT INTO erp_lawyers (name, oab) VALUES ('Advogada Teste', 'SP 123456')");
        $lawyer = (int)$this->pdo->lastInsertId();
        $filed = $this->legalCase('101', 'filed', -100, null, 300000, $lawyer);
        $this->link($filed, $this->income('1.000,00', true), 100000);
        $this->link($filed, $this->income('2.000,00', false), 200000);
        $preparing = $this->legalCase('102', 'preparing', -10, null, 50000, null);
        $this->link($preparing, $this->income('500,00', false), 50000);
        $this->legalCase('103', 'closed', -400, -30, 80000, null);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new LegalCasePortfolio($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([1, 1, 0, 0, 1], array_column($report['rows'], 'cases'));
        self::assertSame(['cases' => 1, 'claimed_cents' => 300000, 'recovered_cents' => 100000, 'still_open_cents' => 200000, 'stale' => 1, 'without_lawyer' => 0,
            'avg_age_days' => 100], array_diff_key($report['rows'][1], array_flip(['status', 'status_label'])));
        self::assertSame([10, 100, null, null, 370], array_column($report['rows'], 'avg_age_days'));
        self::assertSame(['cases' => 3, 'claimed_cents' => 430000, 'recovered_cents' => 100000, 'still_open_cents' => 250000, 'stale' => 1, 'without_lawyer' => 2],
            array_diff_key($report['totals'], ['avg_age_days' => true]));
        self::assertSame([2, 1], [$report['opened_12m'], $report['closed_12m']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Advogada', 'Proc. teste', 'Vara teste', 'Andamento teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new LegalCasePortfolio($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function legalCase(string $unit, string $status, int $openedDays, ?int $closedDays, int $claimed, ?int $lawyer): int
    {
        $day = static fn (int $d): string => (new \DateTimeImmutable("{$d} days"))->format('Y-m-d');
        $this->pdo->prepare('INSERT INTO erp_legal_cases (condominium_id, unit_id, lawyer_id, status, case_number, court, opened_on, claimed_cents, closed_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$this->condominium, $this->units[$unit], $lawyer, $status, 'Proc. teste', 'Vara teste', $day($openedDays),
            $claimed, $closedDays !== null ? $day($closedDays) : null]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_legal_case_events (case_id, kind, note, happened_on) VALUES (?, 'opened', 'Andamento teste', ?)")->execute([$id, $day($openedDays)]);
        return $id;
    }

    private function income(string $amount, bool $paid): int
    {
        return (int)$this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->chartAccount,
            'amount_cents' => $amount, 'competence' => '2026-06', 'description' => 'Cota condominial', 'due_on' => '2026-06-10']
            + ($paid ? ['paid_on' => (new \DateTimeImmutable('-5 days'))->format('Y-m-d'), 'bank_account_id' => $this->bank] : []))['id'];
    }

    private function link(int $case, int $entry, int $amount): void
    {
        $this->pdo->prepare('INSERT INTO erp_legal_case_entries (case_id, entry_id, amount_cents) VALUES (?, ?, ?)')->execute([$case, $entry, $amount]);
    }
}
