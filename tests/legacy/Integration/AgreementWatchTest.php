<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\AgreementWatch;
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0207: acompanhamento dos acordos pela regra de quebra do condomínio, somente leitura e no escopo. */
final class AgreementWatchTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    /** @var array<string, int> */
    private array $agreements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $account = $this->service->save($this->admin, 'chart', ['code' => '1.02', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Acordos'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        $units = [];
        foreach (['101', '102'] as $number) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $debts = [];
        $invoices = new Invoices($this->pdo);
        foreach (['2026-08', '2026-09'] as $competence) {
            $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type,
                'competence' => $competence, 'due_on' => $competence . '-10'])['id']);
            foreach ($invoice['items'] as $item) {
                $debts[$item['unit_label']][] = $item['entry_id'];
            }
        }
        foreach (['101' => '2026-12-10', '102' => '2027-02-20'] as $unit => $first) {
            $this->agreements[$unit] = (new Agreements($this->pdo))->create($this->admin, ['unit_id' => $units[$unit], 'entry_ids' => $debts[$unit],
                'installments' => 3, 'first_due_on' => $first, 'chart_account_id' => $account])['id'];
        }
    }

    public function testClassifiesByTheCondominiumRuleWithoutChangingAnything(): void
    {
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $r = (new AgreementWatch($this->pdo))->build($this->admin, $this->alpha, '2027-02-25');
        self::assertSame([[$this->agreements['101'], 'breach_due', 3, 77, 100000], [$this->agreements['102'], 'late', 1, 5, 33334]],
            array_map(static fn (array $i): array => [$i['agreement_id'], $i['state'], $i['overdue_installments'], $i['max_days_late'], $i['overdue_cents']], $r['items']),
            'Regra padrão: quebra com mais de 30 dias ou 2 parcelas vencidas; a mais grave primeiro.');
        self::assertSame([26, 1, '2027-03-20', '101'], [$r['items'][1]['days_to_breach'], $r['items'][1]['installments_to_breach'], $r['items'][1]['next_due_on'],
            $r['items'][0]['unit_label']]);
        self::assertSame([1, 1, 0], [$r['summary']['breach_due']['agreements'], $r['summary']['late']['agreements'], $r['summary']['on_track']['agreements']]);

        (new BillingRules($this->pdo))->save($this->admin, $this->alpha, ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'IPCA',
            'breach_days_late' => 90, 'breach_open_installments' => 0]);
        $relaxed = (new AgreementWatch($this->pdo))->build($this->admin, $this->alpha, '2027-02-25');
        self::assertSame([['late', 14, null], ['late', 86, null]], array_map(static fn (array $i): array => [$i['state'], $i['days_to_breach'], $i['installments_to_breach']],
            $relaxed['items']), 'Regra do condomínio: 90 dias, sem limite de parcelas.');

        $before = (new AgreementWatch($this->pdo))->build($this->admin, null, '2026-12-01');
        self::assertSame([0, 0, 2, []], [$before['summary']['breach_due']['agreements'], $before['summary']['late']['agreements'],
            $before['summary']['on_track']['agreements'], $before['items']], 'Antes do primeiro vencimento, tudo em dia.');

        self::assertSame(['active', 'active'], $this->pdo->query('SELECT status FROM erp_agreements ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN), 'Nada é quebrado.');
        self::assertSame($outbox, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(), 'Nenhum aviso.');
        self::assertSame($audits + 1, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Só a auditoria da regra salva no teste.');
    }

    public function testScope(): void
    {
        $limited = $this->user([$this->beta]);
        try {
            (new AgreementWatch($this->pdo))->build($limited, $this->alpha, '2027-02-25');
            self::fail('Leu acordos de condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $all = (new AgreementWatch($this->pdo))->build($limited, null, '2027-02-25');
        self::assertSame([0, []], [array_sum(array_column($all['summary'], 'agreements')), $all['items']], 'Os acordos da Alpha não aparecem para quem só vê a Beta.');
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
