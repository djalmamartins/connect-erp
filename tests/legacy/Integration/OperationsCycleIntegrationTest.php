<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Occurrences;
use Source\Services\Erp\OperationalDashboard;
use Source\Services\Erp\PanelSummaries;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Operacional de ponta a ponta (ciclos 19 a 23), encadeando os serviços como o síndico e a
 * administradora usam: reserva aprovada → cancelada → lista de espera chamada → avisos despachados
 * só para a caixa de teste; manutenção atrasada → concluída → próxima; ocorrência → chamado no Help
 * Desk; dashboard e painel refletindo cada passo. No fim, um usuário de outro condomínio não alcança
 * nada. Cada serviço tem o próprio teste; este confere que a saída de um é a entrada certa do próximo.
 */
final class OperationsCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    /** @var list<array{string, string, string, string}> assunto, corpo, e-mail, nome */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
    }

    public function testReservationToNoticesMaintenanceTicketAndScope(): void
    {
        $day = static fn (int $n): string => (new \DateTimeImmutable('today'))->modify("{$n} days")->format('Y-m-d');
        $units = [];
        foreach (['101' => 'ana@morador.test', '102' => 'bia@morador.test'] as $number => $email) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => (string)$number, 'type' => 'apartamento'])['id'];
            $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $units[$number], 'relationship' => 'owner',
                'name' => "Morador {$number}", 'document' => self::validCpf(), 'email' => $email]);
        }
        $dashboard = new OperationalDashboard($this->pdo);
        $cards = fn (): array => $dashboard->summary($this->admin, $this->alpha)['cards'];

        // 1. Reserva em área com aprovação: nasce aguardando e aparece no dashboard; aprovada, vira aviso.
        $date = $day(10);
        $area = $this->service->save($this->admin, 'common_areas', ['condominium_id' => $this->alpha, 'name' => 'Salão', 'booking_mode' => 'approval'])['id'];
        $input = ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $units['101'], 'resident_name' => 'Morador 101',
            'reserved_on' => $date, 'starts_time' => '18:00', 'ends_time' => '22:00'];
        $reservation = $this->service->save($this->admin, 'reservations', $input)['id'];
        self::assertSame(1, $cards()['reservations_pending']);
        $this->service->save($this->admin, 'reservations', ['status' => 'confirmed'] + $input, $reservation);
        self::assertSame(0, $cards()['reservations_pending']);

        // 2. A 102 entra na lista de espera; a reserva é cancelada e a vez passa para ela.
        $waiting = $this->service->save($this->admin, 'waitlist', ['condominium_id' => $this->alpha, 'common_area_id' => $area, 'unit_id' => $units['102'],
            'resident_name' => 'Morador 102', 'desired_on' => $date])['id'];
        $this->service->save($this->admin, 'reservations', ['status' => 'inactive'] + $input, $reservation);
        self::assertSame(1, $cards()['waitlist_to_call']);
        $call = $dashboard->callWaitlist($this->admin, $reservation);
        self::assertSame($waiting, $call['waitlist_id']);
        self::assertSame(0, $cards()['waitlist_to_call']);

        // 3. Os dois avisos saem pela fila e, fora de produção, só para a caixa de teste.
        $events = $this->pdo->query("SELECT event_type FROM erp_notification_outbox WHERE condominium_id = {$this->alpha} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['reservation.approved', 'waitlist.called'], $events);
        $summary = $this->dispatcher()->dispatch();
        self::assertSame(['sandbox', 2], [$summary['mode'], $summary['sent']]);
        self::assertSame(['caixa@mailpit.local'], array_values(array_unique(array_column($this->queued, 2))), 'Nunca o e-mail do morador.');
        self::assertSame(0, $this->dispatcher()->dispatch()['processed'], 'Despachar de novo não reenvia.');

        // 4. Manutenção atrasada: conta no dashboard, sai ao concluir e gera a próxima uma vez.
        $maintenanceInput = ['condominium_id' => $this->alpha, 'service' => 'Bombas', 'planned_on' => $day(-3), 'frequency' => 'mensal'];
        $maintenance = $this->service->save($this->admin, 'maintenances', $maintenanceInput)['id'];
        self::assertSame(1, $cards()['maintenances_late']);
        $this->service->save($this->admin, 'maintenances', ['done_on' => $day(-1), 'status' => 'done'] + $maintenanceInput, $maintenance);
        self::assertSame(0, $cards()['maintenances_late']);
        $next = $dashboard->nextMaintenance($this->admin, $maintenance);
        self::assertSame('active', $next['status']);
        self::assertGreaterThan($maintenanceInput['planned_on'], $next['planned_on']);

        // 5. Ocorrência aberta vira chamado no Help Desk e segue contando até ser resolvida.
        $occurrence = $this->service->save($this->admin, 'occurrences', ['condominium_id' => $this->alpha, 'unit_id' => $units['102'], 'subject' => 'Vazamento',
            'priority' => 'alta', 'occurred_on' => $day(0), 'description' => 'Água pingando na garagem.'])['id'];
        $ticket = (new Occurrences($this->pdo))->openTicket($this->admin, $occurrence);
        self::assertSame($this->alpha, (int)$this->pdo->query('SELECT condominium_id FROM studio_support_tickets WHERE id = ' . (int)$ticket['ticket_id'])->fetchColumn());
        self::assertSame(1, $cards()['occurrences_open']);

        // 6. Painel do condomínio traz os mesmos números do dashboard.
        $panels = new PanelSummaries($this->pdo, null, static fn (string $permission, User $user): bool => true);
        self::assertSame($cards()['occurrences_open'], $panels->condominium($this->admin, $this->alpha)['operations']['cards']['occurrences_open']);

        // 7. Usuário só do Beta não alcança nada do Alpha.
        $outsider = $this->user([$this->beta]);
        $newMaintenance = (int)$next['id'];
        foreach ([
            fn () => $dashboard->summary($outsider, $this->alpha),
            fn () => $dashboard->callWaitlist($outsider, $reservation),
            fn () => $dashboard->nextMaintenance($outsider, $newMaintenance),
            fn () => (new Occurrences($this->pdo))->openTicket($outsider, $occurrence),
            fn () => $panels->condominium($outsider, $this->alpha),
            fn () => $panels->unit($outsider, $units['101']),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->dispatcher()->list($outsider)['total'], 'Avisos de outro condomínio não aparecem.');
        self::assertSame(0, $dashboard->summary($outsider)['cards']['occurrences_open'], 'Sem condomínio informado, soma só o escopo dele.');
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher($this->pdo, 'development', 'sandbox', 'caixa@mailpit.local', function (string $subject, string $body, string $email, string $name): int {
            $this->queued[] = [$subject, $body, $email, $name];
            return count($this->queued);
        });
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
