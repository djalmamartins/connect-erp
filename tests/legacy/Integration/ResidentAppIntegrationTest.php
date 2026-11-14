<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentLinks;
use Source\Services\Resident\ResidentPortal;

/**
 * Aplicativo do morador (ADR 0302): vínculo válido, matriz desligada por padrão, isolamento por unidade e
 * condomínio, cadastros pelas regras do ERP e auditoria.
 */
final class ResidentAppIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $unitA;
    private int $unitB;
    private int $unitBeta;
    private User $admin;
    private User $morador;
    private User $vizinho;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->unitA = $this->unit($this->alpha, '101');
        $this->unitB = $this->unit($this->alpha, '102');
        $this->unitBeta = $this->unit($this->beta, '201');
        $adminId = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($adminId, $this->alpha);
        $this->admin = (new User())->findById($adminId);
        $this->morador = $this->resident($this->alpha, $this->unitA, 'Ana Moradora', 'owner');
        $this->vizinho = $this->resident($this->alpha, $this->unitB, 'Beto Vizinho', 'resident');
    }

    public function testLinksAreFailClosed(): void
    {
        $links = new ResidentLinks($this->pdo);
        $staff = (new User())->findById($this->createUser());
        self::assertSame([], $links->forUser($staff), 'Conta sem vínculo não entra no app.');
        $link = $links->forUser($this->morador)[0];
        self::assertSame([$this->alpha, $this->unitA, 'Ana Moradora'], [$link['condominium_id'], $link['unit_id'], $link['name']]);
        self::assertSame([], $link['features'], 'Tudo começa desligado.');

        $this->enable($this->alpha, ['boletos', 'reservations']);
        self::assertSame(['boletos', 'reservations'], $links->forUser($this->morador)[0]['features']);
        self::assertSame(['reservations'], $links->forUser($this->vizinho)[0]['features'], 'Morador sem responsabilidade financeira não vê boletos.');

        $this->pdo->prepare('UPDATE erp_residents SET ends_on = ? WHERE user_id = ?')->execute([date('Y-m-d', strtotime('-1 day')), (int)$this->morador->id]);
        self::assertSame([], $links->forUser($this->morador), 'Vínculo encerrado sai do app.');
        $this->pdo->prepare('UPDATE erp_residents SET ends_on = NULL WHERE user_id = ?')->execute([(int)$this->morador->id]);
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->unitA]);
        self::assertSame([], $links->forUser($this->morador), 'Unidade inativa sai do app.');
    }

    public function testDisabledFeatureIsRefused(): void
    {
        $link = $this->link($this->morador);
        $portal = new ResidentPortal($this->pdo);
        foreach (['charges' => fn () => $portal->charges($link), 'deliveries' => fn () => $portal->deliveries($link), 'documents' => fn () => $portal->documents($link),
            'visits' => fn () => $portal->visits($link), 'assemblies' => fn () => $portal->assemblies($link)] as $what => $call) {
            try {
                $call();
                self::fail("{$what} deveria estar desligado.");
            } catch (ResidentFeatureDisabled) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $portal->summary($link)['features']);
    }

    public function testChargesOnlyShowTheOwnUnit(): void
    {
        $this->enable($this->alpha, ['boletos']);
        $account = $this->chartAccount();
        $today = date('Y-m-d');
        $mine = $this->entry($account, $this->unitA, 50000, date('Y-m-d', strtotime('-10 days')));
        $this->entry($account, $this->unitA, 50000, date('Y-m-d', strtotime('+20 days')));
        $this->entry($account, $this->unitB, 99900, $today);
        $bank = $this->bankAccount();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, digitable_line)
            VALUES (?, ?, ?, 'sandbox', 'registered', '0001', 50000, ?, '23790.00000 00000.000000 00000.000000 1 00000000050000')")
            ->execute([$this->alpha, $bank, $mine, date('Y-m-d', strtotime('-10 days'))]);

        $statement = (new ResidentPortal($this->pdo))->charges($this->link($this->morador), null, null, $today);
        self::assertCount(2, $statement['lines'], 'Só as contas da própria unidade.');
        self::assertSame(['overdue_cents' => 50000, 'open' => 50000], ['overdue_cents' => $statement['totals']['overdue_cents'], 'open' => $statement['totals']['open_cents']]);
        $overdue = array_values(array_filter($statement['lines'], static fn (array $l): bool => $l['situation'] === 'overdue'))[0];
        self::assertStringStartsWith('23790', (string)$overdue['charge']['digitable_line']);
        self::assertFalse($overdue['charge']['pdf_available'], 'Sandbox não tem PDF do banco.');
        $this->expectException(RegistryNotFound::class);
        (new ResidentPortal($this->pdo))->chargePdf($this->link($this->vizinho, ['boletos']), (int)$this->pdo->query('SELECT id FROM erp_bank_charges')->fetchColumn());
    }

    public function testReservationsFollowTheErpRulesAndStayInTheUnit(): void
    {
        $this->enable($this->alpha, ['reservations']);
        $free = $this->area($this->alpha, 'Churrasqueira', 'free');
        $approval = $this->area($this->alpha, 'Salão', 'approval');
        $foreign = $this->area($this->beta, 'Quadra Beta', 'free');
        $portal = new ResidentPortal($this->pdo);
        $date = date('Y-m-d', strtotime('+5 days'));

        $confirmed = $portal->requestReservation($this->morador, $this->link($this->morador), ['common_area_id' => $free, 'reserved_on' => $date, 'starts_time' => '10:00', 'ends_time' => '14:00',
            'unit_id' => $this->unitB, 'condominium_id' => $this->beta]);
        self::assertSame('confirmed', $confirmed['status'], 'Área de reserva livre confirma na hora.');
        $row = $this->pdo->query('SELECT condominium_id, unit_id, resident_name, created_by FROM erp_reservations WHERE id = ' . (int)$confirmed['id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([$this->alpha, $this->unitA, 'Ana Moradora', (int)$this->morador->id], [(int)$row['condominium_id'], (int)$row['unit_id'], $row['resident_name'], (int)$row['created_by']],
            'Condomínio e unidade vêm do vínculo, não do pedido.');

        try {
            $portal->requestReservation($this->vizinho, $this->link($this->vizinho), ['common_area_id' => $free, 'reserved_on' => $date, 'starts_time' => '12:00', 'ends_time' => '16:00']);
            self::fail('Sobreposição deveria ser recusada.');
        } catch (RegistryValidationException $error) {
            self::assertStringContainsString('reservada', implode(' ', $error->errors));
        }
        $pending = $portal->requestReservation($this->vizinho, $this->link($this->vizinho), ['common_area_id' => $approval, 'reserved_on' => $date]);
        self::assertSame('active', $pending['status'], 'Área com aprovação fica aguardando.');
        try {
            $portal->requestReservation($this->morador, $this->link($this->morador), ['common_area_id' => $foreign, 'reserved_on' => $date]);
            self::fail('Área de outro condomínio deveria ser recusada.');
        } catch (RegistryValidationException $error) {
            self::assertArrayHasKey('common_area_id', $error->errors);
        }

        self::assertCount(1, $portal->reservations($this->link($this->morador))['reservations'], 'Cada unidade vê só as suas reservas.');
        try {
            $portal->cancelReservation($this->morador, $this->link($this->morador), (int)$pending['id']);
            self::fail('Cancelar a reserva do vizinho deveria falhar.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        $portal->cancelReservation($this->morador, $this->link($this->morador), (int)$confirmed['id']);
        self::assertSame('inactive', $this->pdo->query('SELECT status FROM erp_reservations WHERE id = ' . (int)$confirmed['id'])->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_reservations' AND action = 'update'")->fetchColumn());
        $this->expectException(DomainException::class);
        $portal->cancelReservation($this->morador, $this->link($this->morador), (int)$confirmed['id']);
    }

    public function testAnnouncementsDeliveriesVisitsAndDocuments(): void
    {
        $this->enable($this->alpha, ['announcements', 'deliveries', 'visitors', 'documents', 'occurrences']);
        $portal = new ResidentPortal($this->pdo);
        $this->pdo->prepare("INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at) VALUES (?, 'general', 'Para todos', 'Texto', 'all', NOW() - INTERVAL 1 HOUR),
            (?, 'general', 'Só bloco do vizinho', 'Texto', 'units', NOW() - INTERVAL 1 HOUR), (?, 'general', 'Agendado', 'Texto', 'all', NOW() + INTERVAL 1 DAY),
            (?, 'general', 'Outro condomínio', 'Texto', 'all', NOW() - INTERVAL 1 HOUR)")->execute([$this->alpha, $this->alpha, $this->alpha, $this->beta]);
        $targeted = (int)$this->pdo->query("SELECT id FROM erp_announcements WHERE subject = 'Só bloco do vizinho'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json) VALUES (?, 'announcement.published', ?, 'erp_announcements', ?, '{}')")
            ->execute([$this->alpha, "announcement:{$targeted}:{$this->unitB}", $targeted]);
        self::assertSame(['Para todos'], array_column($portal->announcements($this->link($this->morador)), 'subject'));
        self::assertEqualsCanonicalizing(['Para todos', 'Só bloco do vizinho'], array_column($portal->announcements($this->link($this->vizinho)), 'subject'));

        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on) VALUES (?, ?, 'Ana', 'correios', CURDATE()), (?, ?, 'Beto', 'amazon', CURDATE())")
            ->execute([$this->alpha, $this->unitA, $this->alpha, $this->unitB]);
        self::assertSame(['Ana'], array_column($portal->deliveries($this->link($this->morador)), 'recipient'));
        self::assertSame(1, $portal->summary($this->link($this->morador))['deliveries_waiting']);

        $visit = $portal->authorizeVisit($this->morador, $this->link($this->morador), ['visitor_name' => 'Técnico da internet', 'visitor_document' => '12.345.678-9', 'expected_on' => date('Y-m-d')]);
        $visits = $portal->visits($this->link($this->morador));
        self::assertSame('******789', $visits[0]['visitor_document'], 'Documento do visitante mascarado.');
        self::assertSame('Ana Moradora', $this->pdo->query('SELECT authorized_by FROM erp_visit_forecasts WHERE id = ' . (int)$visit['id'])->fetchColumn());
        self::assertSame([], $portal->visits($this->link($this->vizinho)));
        try {
            $portal->cancelVisit($this->vizinho, $this->link($this->vizinho), (int)$visit['id']);
            self::fail('O vizinho não cancela a visita de outra unidade.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        $portal->cancelVisit($this->morador, $this->link($this->morador), (int)$visit['id']);

        $occurrence = $portal->reportOccurrence($this->morador, $this->link($this->morador), ['subject' => 'Vazamento na garagem', 'description' => 'Água no subsolo perto da vaga 12.']);
        self::assertSame([(int)$occurrence['id']], array_column($portal->occurrences($this->link($this->morador)), 'id'));
        self::assertSame([], $portal->occurrences($this->link($this->vizinho)));

        $this->pdo->prepare("INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256, shared_with_residents) VALUES
            (?, 'outros', 'Regimento interno', 'regimento.pdf', '1/2026/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf', 'application/pdf', 100, REPEAT('a', 64), 1),
            (?, 'contratos', 'Contrato de limpeza', 'contrato.pdf', '1/2026/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf', 'application/pdf', 100, REPEAT('b', 64), 0),
            (?, 'outros', 'Regimento Beta', 'beta.pdf', '2/2026/cccccccccccccccccccccccccccccccc.pdf', 'application/pdf', 100, REPEAT('c', 64), 1)")->execute([$this->alpha, $this->alpha, $this->beta]);
        self::assertSame(['Regimento interno'], array_column($portal->documents($this->link($this->morador)), 'title'), 'Só os marcados do próprio condomínio.');
        $hidden = (int)$this->pdo->query("SELECT id FROM erp_documents WHERE title = 'Contrato de limpeza'")->fetchColumn();
        $this->expectException(RegistryNotFound::class);
        $portal->documentFile($this->link($this->morador), $hidden);
    }

    public function testStaffSharesDocumentWithAudit(): void
    {
        $this->pdo->prepare("INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256) VALUES (?, 'atas', 'Ata AGO', 'ata.pdf', '1/2026/dddddddddddddddddddddddddddddddd.pdf', 'application/pdf', 10, REPEAT('d', 64))")
            ->execute([$this->alpha]);
        $id = (int)$this->pdo->lastInsertId();
        $disk = new \Source\Services\Erp\VirtualDisk($this->pdo);
        self::assertFalse($disk->find($this->admin, $id)['shared_with_residents']);
        self::assertTrue($disk->share($this->admin, $id, true)['shared_with_residents']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'update'")->fetchColumn());
    }

    private function link(User $user, array $extraFeatures = []): array
    {
        $link = (new ResidentLinks($this->pdo))->forUser($user)[0];
        $link['features'] = array_values(array_unique(array_merge($link['features'], $extraFeatures)));
        return $link;
    }

    /** @param list<string> $features */
    private function enable(int $condominium, array $features): void
    {
        foreach ($features as $feature) {
            (new ResidentPortalPermissions($this->pdo))->set($this->admin, $condominium, $feature, true);
        }
    }

    private function unit(int $condominium, string $number): int
    {
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, ?, 'apartamento')")->execute([$condominium, $number]);
        return (int)$this->pdo->lastInsertId();
    }

    private function resident(int $condominium, int $unit, string $name, string $relationship): User
    {
        $userId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, email, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$condominium, $unit, (string)$unit, $relationship, $name, 'm' . $userId . '@morador.test', $userId]);
        return (new User())->findById($userId);
    }

    private function area(int $condominium, string $name, string $mode): int
    {
        $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, ?, ?)")->execute([$condominium, $name, $mode]);
        return (int)$this->pdo->lastInsertId();
    }

    private function chartAccount(): int
    {
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.91', 'Taxa condominial', 'receita', 'analitica')")->execute([$this->alpha]);
        return (int)$this->pdo->lastInsertId();
    }

    private function bankAccount(): int
    {
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind) VALUES (?, 'Conta', '077', '0001', '1234567-8', 'corrente')")->execute([$this->alpha]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(int $account, int $unit, int $cents, string $due): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, unit_id, description, amount_cents, competence, due_on) VALUES (?, 'receita', ?, ?, 'Taxa condominial', ?, ?, ?)")
            ->execute([$this->alpha, $account, $unit, $cents, substr($due, 0, 7), $due]);
        return (int)$this->pdo->lastInsertId();
    }
}
