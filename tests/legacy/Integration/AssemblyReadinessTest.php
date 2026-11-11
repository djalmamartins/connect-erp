<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\AssemblyReadiness;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0211: conferência antes da assembleia com os dados reais e as regras da própria assembleia. */
final class AssemblyReadinessTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];
    private int $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        // Acesso a todos os condomínios, como no EntriesTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        foreach (['101' => '0,400000', '102' => '0,350000', '103' => '0,250000'] as $number => $fraction) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento',
                'ideal_fraction' => $fraction])['id'];
        }
        $this->registry->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->registry->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
    }

    public function testFindsWhatWouldGoWrongWithoutChangingAnything(): void
    {
        $assembly = $this->assembly('2026-11-15 19:00');
        $debt = $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'unit_id' => $this->units['101'], 'competence' => '2026-10', 'description' => 'Taxa outubro', 'amount_cents' => '500,00', 'due_on' => '2026-10-10'])['id'];
        $now = new \DateTimeImmutable('2026-11-11 10:00:00');

        $r = (new AssemblyReadiness($this->pdo))->check($this->admin, $assembly, $now);
        self::assertSame([false, 3, 1], [$r['ready'], $r['units'], $r['debtors']]);
        self::assertSame(['notice_deadline', 'quorum_unreachable', 'debtors', 'no_second_call'], array_column($r['checks'], 'code'),
            'Edital em menos de 8 dias; a 101 (40%) devendo deixa os dois terços fora de alcance.');
        self::assertSame([[60.0, true], [60.0, false]], array_map(static fn (array $i): array => [$i['eligible_percent'], $i['reachable']], $r['items']));
        foreach (['unit_label', 'unit_id', 'overdue_cents', 'ideal_fraction'] as $key) {
            self::assertStringNotContainsString('"' . $key . '"', (string)json_encode($r), 'Sem unidade nem valor na resposta: ' . $key);
        }

        $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'unit_id' => $this->units['101'], 'competence' => '2026-10', 'description' => 'Taxa outubro', 'amount_cents' => '500,00', 'due_on' => '2026-10-10',
            'paid_on' => '2026-11-10', 'bank_account_id' => $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium,
                'name' => 'Conta 1', 'bank_code' => '341', 'branch' => '1234', 'account_number' => '11111-1', 'kind' => 'corrente'])['id']], $debt);
        $paid = (new AssemblyReadiness($this->pdo))->check($this->admin, $assembly, $now);
        self::assertSame([0, [true, true]], [$paid['debtors'], array_column($paid['items'], 'reachable')], 'Pago: todos aptos.');

        $this->pdo->exec("UPDATE erp_units SET ideal_fraction = NULL WHERE id = {$this->units['103']}");
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $missing = (new AssemblyReadiness($this->pdo))->check($this->admin, $assembly, $now);
        self::assertContains('fraction_missing', array_column($missing['checks'], 'code'));
        self::assertSame(['draft', $audits, $outbox], [(string)$this->pdo->query("SELECT status FROM erp_assemblies WHERE id = {$assembly}")->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn()], 'A conferência não grava, não audita e não envia.');
    }

    public function testScopeAndClosedAssembly(): void
    {
        $assembly = $this->assembly('2026-12-20 19:00');
        $limitedId = $this->createUser(['level' => 2]);
        $limited = (new User())->findById($limitedId);
        try {
            (new AssemblyReadiness($this->pdo))->check($limited, $assembly);
            self::fail('Conferiu assembleia fora do escopo.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        (new Assemblies($this->pdo))->cancel($this->admin, $assembly, 'Remarcada para janeiro');
        try {
            (new AssemblyReadiness($this->pdo))->check($this->admin, $assembly);
            self::fail('Conferiu assembleia cancelada.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('encerrada ou cancelada', $exception->getMessage());
        }
    }

    private function assembly(string $firstCall): int
    {
        return (new Assemblies($this->pdo))->create($this->admin, ['condominium_id' => $this->condominium, 'kind' => 'extraordinaria', 'mode' => 'presencial',
            'title' => 'Assembleia de teste', 'location' => 'Salão de festas', 'first_call_at' => $firstCall,
            'items' => [['title' => 'Aprovar as contas', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
                ['title' => 'Mudar a convenção', 'vote_basis' => 'fraction', 'quorum' => 'two_thirds']]])['id'];
    }
}
