<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ReservationFeeBilling;

/** ADR 0212: as taxas das reservas confirmadas viram cobranças extras pelo EntryShortcuts::createCharge, uma vez só, tudo ou nada. */
final class ReservationFeeBillingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;
    private int $area;
    private string $due;
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
        // Acesso a todos os condomínios, como no EntriesTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        $this->registry->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->registry->save($this->admin, 'chart', ['code' => '1.05', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxas de reserva'])['id'];
        foreach (['101', '102'] as $number) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, 'Salão de festas', 'approval')")->execute([$this->condominium]);
        $this->area = (int)$this->pdo->lastInsertId();
        $this->due = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
    }

    public function testBillsOnceThroughTheExtraCharge(): void
    {
        $party = $this->reservation('101', '2026-10-05', 15000);
        $this->reservation('102', '2026-10-20', null);                     // sem taxa
        $this->reservation('101', '2026-10-12', 5000, 'active');           // aguardando aprovação
        $earlier = $this->reservation('102', '2026-10-25', 8000);           // já cobrada à mão, antes
        $this->reservation('101', '2026-11-02', 15000);                    // fora do período
        $this->reservation('102', '2026-10-28', 3000, 'confirmed', '2026-10-29');   // excluída
        (new EntryShortcuts($this->pdo))->createCharge($this->admin, ['condominium_id' => $this->condominium, 'kind' => 'extra', 'method' => 'fixed',
            'unit_ids' => [$this->units['102']], 'amount_cents' => 8000, 'description' => 'Taxa do salão', 'competence' => '2026-10', 'due_on' => $this->due,
            'chart_account_id' => $this->account, 'reservation_id' => $earlier]);
        $input = ['condominium_id' => $this->condominium, 'from' => '2026-10-01', 'to' => '2026-10-31', 'due_on' => $this->due, 'chart_account_id' => $this->account];

        $preview = (new ReservationFeeBilling($this->pdo))->bill($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 1, 15000, 1, [$party]], [$preview['dry_run'], $preview['changed'], $preview['count'], $preview['total_cents'],
            $preview['already_billed'], array_column($preview['reservations'], 'id')]);
        self::assertSame(1, $this->charges(), 'dry_run não grava.');

        $done = (new ReservationFeeBilling($this->pdo))->bill($this->admin, $input);
        self::assertSame(1, $done['changed']);
        $charge = $this->pdo->query("SELECT kind, method, total_cents, units_count, competence, reservation_id, description FROM erp_extra_charges WHERE id = {$done['reservations'][0]['charge_id']}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['extra', 'fixed', 15000, 1, '2026-10', $party, 'Taxa de reserva: Salão de festas em 05/10/2026'], [$charge['kind'], $charge['method'],
            (int)$charge['total_cents'], (int)$charge['units_count'], (string)$charge['competence'], (int)$charge['reservation_id'], $charge['description']]);
        $entry = $this->pdo->query("SELECT unit_id, nature, amount_cents, competence, due_on FROM erp_entries WHERE extra_charge_id = {$done['reservations'][0]['charge_id']}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([$this->units['101'], 'receita', 15000, '2026-10', $this->due], [(int)$entry['unit_id'], $entry['nature'], (int)$entry['amount_cents'],
            (string)$entry['competence'], substr((string)$entry['due_on'], 0, 10)]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_reservations' AND action = 'bill_fee'")->fetchColumn());

        $again = (new ReservationFeeBilling($this->pdo))->bill($this->admin, $input);
        self::assertSame([0, 2], [$again['count'], $again['already_billed']], 'Não cobra duas vezes.');

        (new EntryShortcuts($this->pdo))->cancelCharge($this->admin, $done['reservations'][0]['charge_id'], 'Cobrada com o valor errado');
        self::assertSame([$party], array_column((new ReservationFeeBilling($this->pdo))->bill($this->admin, $input + ['dry_run' => true])['reservations'], 'id'),
            'Cancelada a cobrança, a reserva volta a entrar.');
    }

    public function testClosedMonthRefusesEverythingAndCreateChargeRules(): void
    {
        $party = $this->reservation('101', '2026-10-05', 15000);
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-10', 'closed')")->execute([$this->condominium]);
        try {
            (new ReservationFeeBilling($this->pdo))->bill($this->admin, ['condominium_id' => $this->condominium, 'from' => '2026-10-01', 'to' => '2026-10-31',
                'due_on' => $this->due, 'chart_account_id' => $this->account]);
            self::fail('Cobrou em mês fechado.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$party, $exception->errors['_reservation'] ?? null);
            self::assertStringContainsString('2026-10', (string)($exception->errors['_form'] ?? ''));
        }
        self::assertSame(0, $this->charges());

        $base = ['condominium_id' => $this->condominium, 'method' => 'fixed', 'amount_cents' => 1000, 'description' => 'Teste', 'competence' => '2026-11',
            'due_on' => $this->due, 'chart_account_id' => $this->account, 'reservation_id' => $party];
        foreach ([[['kind' => 'extra', 'unit_ids' => [$this->units['101'], $this->units['102']]], 'A cobrança de uma reserva é de uma unidade só.'],
            [['kind' => 'fine', 'unit_ids' => [$this->units['102']], 'reason' => 'Barulho depois das 22h'], 'A reserva da multa precisa ser desta unidade.'],
            [['kind' => 'extra', 'unit_ids' => [$this->units['102']]], 'A reserva da cobrança precisa ser desta unidade.']] as [$change, $message]) {
            try {
                (new EntryShortcuts($this->pdo))->createCharge($this->admin, $change + $base);
                self::fail('Aceitou: ' . $message);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $limitedId = $this->createUser(['level' => 2]);
        try {
            (new ReservationFeeBilling($this->pdo))->bill((new User())->findById($limitedId), ['condominium_id' => $this->condominium, 'from' => '2026-10-01',
                'to' => '2026-10-31', 'due_on' => $this->due, 'chart_account_id' => $this->account]);
            self::fail('Cobrou fora do escopo.');
        } catch (RegistryNotFound) {
            self::assertSame(0, $this->charges());
        }
    }

    private function charges(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM erp_extra_charges')->fetchColumn();
    }

    private function reservation(string $unit, string $on, ?int $fee, string $status = 'confirmed', ?string $deleted = null): int
    {
        $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, fee_cents, status, deleted_at)
            VALUES (?, ?, ?, 'Morador de teste', ?, ?, ?, ?)")->execute([$this->condominium, $this->area, $this->units[$unit], $on, $fee, $status, $deleted]);
        return (int)$this->pdo->lastInsertId();
    }
}
