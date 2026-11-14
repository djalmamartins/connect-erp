<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitStatement;
use Source\Services\Erp\VirtualDisk;
use Source\Support\Audit;

/**
 * Dados do aplicativo do morador (ADR 0302). Cada leitura fica presa ao condomínio e à unidade do
 * vínculo (ResidentLinks) e exige a função ligada na matriz do portal. Nada aqui usa o escopo da equipe:
 * o morador nunca escolhe condomínio nem unidade, só o vínculo, que o servidor confere.
 *
 * Cadastros (reserva, lista de espera, ocorrência, visita) passam pelo motor do ERP
 * (RegistryService::createForResident), com as mesmas regras e a mesma auditoria. Cancelar a própria
 * reserva ou visita é um UPDATE condicionado à unidade e à situação, também auditado.
 *
 * @phpstan-type Link array{resident_id:int, name:string, relationship:string, condominium_id:int, unit_id:int, unit_label:string, features:list<string>}
 */
final class ResidentPortal
{
    public const STATEMENT_MONTHS_BACK = 12;
    public const STATEMENT_MONTHS_AHEAD = 3;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** A função está ligada para o vínculo? Senão, ResidentFeatureDisabled. */
    public static function assertFeature(array $link, string $feature): void
    {
        if (!in_array($feature, $link['features'] ?? [], true)) {
            throw new ResidentFeatureDisabled($feature);
        }
    }

    /** Período do extrato: do 1º dia de 12 meses atrás ao último dia de daqui a 3 meses (regra pura). */
    public static function defaultPeriod(string $today): array
    {
        $base = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        return [$base->modify('-' . self::STATEMENT_MONTHS_BACK . ' months')->format('Y-m-d'),
            $base->modify('+' . (self::STATEMENT_MONTHS_AHEAD + 1) . ' months')->modify('-1 day')->format('Y-m-d')];
    }

    /** Data AAAA-MM-DD válida ou null (regra pura). */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /** Resumo da tela inicial, só com as funções ligadas. */
    public function summary(array $link, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $features = $link['features'] ?? [];
        $out = ['features' => $features];
        if (in_array('boletos', $features, true)) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS total, MIN(due_on) AS next_due,
                    COALESCE(SUM(CASE WHEN due_on < :t THEN amount_cents ELSE 0 END), 0) AS overdue
                FROM erp_entries WHERE unit_id = :u AND condominium_id = :c AND nature = 'receita' AND status = 'active'
                  AND deleted_at IS NULL AND paid_on IS NULL");
            $stmt->execute(['t' => $today, 'u' => $link['unit_id'], 'c' => $link['condominium_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['charges'] = ['open_count' => (int)($row['n'] ?? 0), 'open_cents' => (int)($row['total'] ?? 0),
                'overdue_cents' => (int)($row['overdue'] ?? 0), 'next_due_on' => $row['next_due'] ?? null];
        }
        if (in_array('deliveries', $features, true)) {
            $out['deliveries_waiting'] = $this->count("SELECT COUNT(*) FROM erp_deliveries WHERE unit_id = :u AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL", $link);
        }
        if (in_array('reservations', $features, true)) {
            $out['next_reservations'] = array_slice($this->reservationRows($link, $today, true), 0, 3);
        }
        if (in_array('visitors', $features, true)) {
            $out['visits_today'] = $this->count("SELECT COUNT(*) FROM erp_visit_forecasts WHERE unit_id = :u AND condominium_id = :c AND status = 'active'
                AND deleted_at IS NULL AND expected_on <= CURDATE() AND COALESCE(expected_until, expected_on) >= CURDATE()", $link);
        }
        if (in_array('announcements', $features, true)) {
            $out['announcements'] = array_slice($this->announcements($link), 0, 3);
        }
        if (in_array('assemblies', $features, true)) {
            $out['assemblies'] = array_values(array_filter($this->assemblies($link), static fn (array $a): bool => in_array($a['status'], ['convened', 'open'], true)));
        }
        return $out;
    }

    /** Extrato da unidade com o boleto de cada conta (função boletos). */
    public function charges(array $link, mixed $from = null, mixed $to = null, ?string $today = null): array
    {
        self::assertFeature($link, 'boletos');
        $today ??= date('Y-m-d');
        [$defaultFrom, $defaultTo] = self::defaultPeriod($today);
        $from = self::date($from) ?? $defaultFrom;
        $to = self::date($to) ?? $defaultTo;
        if ($from > $to) {
            throw new InvalidArgumentException('A data inicial precisa ser antes da final.');
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.description, e.competence, e.due_on, e.paid_on, e.amount_cents, e.status, e.agreement_role,
                b.id AS charge_id, b.status AS charge_status, b.adapter, b.external_id, b.digitable_line, b.barcode, b.pix_copy_paste
            FROM erp_entries e
            LEFT JOIN erp_bank_charges b ON b.entry_id = e.id AND b.active_key = 1
            WHERE e.unit_id = :u AND e.condominium_id = :c AND e.nature = 'receita' AND e.deleted_at IS NULL
              AND (e.status = 'active' OR (e.status = 'inactive' AND e.agreement_role = 'original'))
              AND e.due_on BETWEEN :f AND :t
            ORDER BY e.due_on DESC, e.id DESC LIMIT 500");
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 'f' => $from, 't' => $to]);
        $lines = [];
        $totals = ['open_cents' => 0, 'overdue_cents' => 0, 'paid_cents' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $situation = UnitStatement::situation(['status' => (string)$row['status'], 'paid_on' => $row['paid_on'], 'due_on' => (string)$row['due_on'],
                'agreement_role' => $row['agreement_role']], $today);
            $amount = (int)$row['amount_cents'];
            match ($situation) {
                'paid' => $totals['paid_cents'] += $amount,
                'overdue' => $totals['overdue_cents'] += $amount,
                'open' => $totals['open_cents'] += $amount,
                default => null,
            };
            $charge = null;
            if ($row['charge_id'] !== null && $situation !== 'renegotiated') {
                $charge = ['id' => (int)$row['charge_id'], 'status' => (string)$row['charge_status'],
                    'digitable_line' => $row['digitable_line'], 'barcode' => $row['barcode'], 'pix_copy_paste' => $row['pix_copy_paste'],
                    'pdf_available' => $row['adapter'] === 'inter' && !empty($row['external_id']) && in_array($row['charge_status'], ['registered', 'paid'], true)];
            }
            $lines[] = ['entry_id' => (int)$row['id'], 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                'due_on' => (string)$row['due_on'], 'paid_on' => $row['paid_on'], 'amount_cents' => $amount, 'situation' => $situation, 'charge' => $charge];
        }
        return ['unit_label' => $link['unit_label'], 'from' => $from, 'to' => $to, 'as_of' => $today, 'totals' => $totals, 'lines' => $lines];
    }

    /** PDF do boleto da unidade (função boletos). */
    public function chargePdf(array $link, int $chargeId, ?BankCharges $charges = null): string
    {
        self::assertFeature($link, 'boletos');
        return ($charges ?? new BankCharges($this->pdo))->residentPdf($chargeId, $link['condominium_id'], $link['unit_id']);
    }

    /** Links públicos da prestação de contas do condomínio (função accountability). */
    public function accountability(array $link): array
    {
        self::assertFeature($link, 'accountability');
        $stmt = $this->pdo->prepare("SELECT id, kind, competence, token, version, generated_at FROM erp_accountability_links
            WHERE condominium_id = :c AND status = 'active' ORDER BY competence DESC, kind LIMIT 36");
        $stmt->execute(['c' => $link['condominium_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'competence' => (string)$r['competence'],
            'version' => (int)$r['version'], 'generated_at' => (string)$r['generated_at'], 'url' => url('/prestacao/' . $r['token'])],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Comunicados que chegaram à unidade (função announcements): os para todos e os enviados à unidade,
     * já liberados (send_at no passado) e não cancelados. O aviso de cobrança não entra aqui.
     */
    public function announcements(array $link): array
    {
        self::assertFeature($link, 'announcements');
        $stmt = $this->pdo->prepare("SELECT a.id, a.subject, a.body, a.send_at FROM erp_announcements a
            WHERE a.condominium_id = :c AND a.kind = 'general' AND a.status = 'active' AND a.send_at <= NOW()
              AND (a.audience = 'all' OR EXISTS (SELECT 1 FROM erp_notification_outbox o
                    WHERE o.event_key = CONCAT('announcement:', a.id, ':', :u) AND o.status <> 'cancelled'))
            ORDER BY a.send_at DESC, a.id DESC LIMIT 50");
        $stmt->execute(['c' => $link['condominium_id'], 'u' => $link['unit_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'subject' => (string)$r['subject'], 'body' => (string)($r['body'] ?? ''),
            'published_at' => (string)$r['send_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Áreas comuns com as regras (função reservations). Área sem reserva não aparece. */
    public function commonAreas(array $link): array
    {
        self::assertFeature($link, 'reservations');
        $stmt = $this->pdo->prepare("SELECT id, name, booking_mode, capacity, booking_fee_cents, min_advance_days, max_advance_days, opening_time, closing_time, rules
            FROM erp_common_areas WHERE condominium_id = :c AND status = 'active' AND deleted_at IS NULL AND booking_mode <> 'none' ORDER BY name");
        $stmt->execute(['c' => $link['condominium_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'booking_mode' => (string)$r['booking_mode'],
            'capacity' => $r['capacity'] !== null ? (int)$r['capacity'] : null, 'fee_cents' => $r['booking_fee_cents'] !== null ? (int)$r['booking_fee_cents'] : null,
            'min_advance_days' => $r['min_advance_days'] !== null ? (int)$r['min_advance_days'] : null,
            'max_advance_days' => $r['max_advance_days'] !== null ? (int)$r['max_advance_days'] : null,
            'opening_time' => $r['opening_time'] !== null ? substr((string)$r['opening_time'], 0, 5) : null,
            'closing_time' => $r['closing_time'] !== null ? substr((string)$r['closing_time'], 0, 5) : null,
            'rules' => (string)($r['rules'] ?? '')], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Reservas e inscrições na lista de espera da unidade, de 30 dias atrás em diante. */
    public function reservations(array $link, ?string $today = null): array
    {
        self::assertFeature($link, 'reservations');
        $today ??= date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT w.id, w.desired_on, w.status, a.name AS area FROM erp_waitlist w JOIN erp_common_areas a ON a.id = w.common_area_id
            WHERE w.unit_id = :u AND w.condominium_id = :c AND w.deleted_at IS NULL AND w.status = 'active' AND w.desired_on >= :t ORDER BY w.desired_on, w.id");
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 't' => $today]);
        return ['reservations' => $this->reservationRows($link, (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d'), false),
            'waitlist' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'area' => (string)$r['area'], 'desired_on' => (string)$r['desired_on']],
                $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function requestReservation(User $user, array $link, array $input, ?RegistryService $registry = null): array
    {
        self::assertFeature($link, 'reservations');
        $input['resident_name'] = $link['name'];
        $area = filter_var($input['common_area_id'] ?? null, FILTER_VALIDATE_INT);
        if ($area !== false && $area !== null) {
            $this->assertAreaOf($link, (int)$area);
        }
        return ($registry ?? new RegistryService($this->pdo))->createForResident($user, 'reservations', $input, $link['condominium_id'], $link['unit_id']);
    }

    public function joinWaitlist(User $user, array $link, array $input, ?RegistryService $registry = null): array
    {
        self::assertFeature($link, 'reservations');
        $input['resident_name'] = $link['name'];
        $area = filter_var($input['common_area_id'] ?? null, FILTER_VALIDATE_INT);
        if ($area !== false && $area !== null) {
            $this->assertAreaOf($link, (int)$area);
        }
        return ($registry ?? new RegistryService($this->pdo))->createForResident($user, 'waitlist', $input, $link['condominium_id'], $link['unit_id']);
    }

    /**
     * Cancela a própria reserva, de hoje em diante: aguardando aprovação sempre; confirmada só sem taxa já
     * cobrada (cobrança extra ativa ligada à reserva). Depois disso, só a administradora.
     */
    public function cancelReservation(User $user, array $link, int $id, ?string $today = null): void
    {
        self::assertFeature($link, 'reservations');
        $today ??= date('Y-m-d');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, status, reserved_on FROM erp_reservations WHERE id = :id AND unit_id = :u AND condominium_id = :c AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute(['id' => $id, 'u' => $link['unit_id'], 'c' => $link['condominium_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RegistryNotFound();
            }
            if (!in_array($row['status'], ['active', 'confirmed'], true) || (string)$row['reserved_on'] < $today) {
                throw new DomainException('Esta reserva não pode mais ser cancelada pelo aplicativo.');
            }
            $billed = $this->pdo->prepare("SELECT 1 FROM erp_extra_charges WHERE reservation_id = :r AND status = 'active' LIMIT 1");
            $billed->execute(['r' => $id]);
            if ($billed->fetchColumn()) {
                throw new DomainException('A taxa desta reserva já foi cobrada. Para cancelar, fale com a administração.');
            }
            $this->pdo->prepare("UPDATE erp_reservations SET status = 'inactive', updated_by = :by WHERE id = :id AND status IN ('active','confirmed')")
                ->execute(['by' => (int)$user->id, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_reservations', $id, ['status' => (string)$row['status']], ['status' => 'inactive', 'channel' => 'resident_app']);
    }

    /** Ocorrências da unidade (função occurrences). */
    public function occurrences(array $link): array
    {
        self::assertFeature($link, 'occurrences');
        $stmt = $this->pdo->prepare("SELECT id, subject, priority, occurred_on, description, status, ticket_id, created_at FROM erp_occurrences
            WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL ORDER BY occurred_on DESC, id DESC LIMIT 100");
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'subject' => (string)$r['subject'], 'priority' => (string)$r['priority'],
            'occurred_on' => (string)$r['occurred_on'], 'description' => (string)$r['description'], 'status' => (string)$r['status'],
            'has_ticket' => $r['ticket_id'] !== null], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function reportOccurrence(User $user, array $link, array $input, ?string $today = null, ?RegistryService $registry = null): array
    {
        self::assertFeature($link, 'occurrences');
        $input['occurred_on'] = ($input['occurred_on'] ?? '') !== '' ? $input['occurred_on'] : ($today ?? date('Y-m-d'));
        $input['priority'] = in_array($input['priority'] ?? null, ['baixa', 'media', 'alta'], true) ? $input['priority'] : 'media';
        return ($registry ?? new RegistryService($this->pdo))->createForResident($user, 'occurrences', $input, $link['condominium_id'], $link['unit_id']);
    }

    /** Encomendas da unidade: as que aguardam retirada e as dos últimos 90 dias (função deliveries). */
    public function deliveries(array $link): array
    {
        self::assertFeature($link, 'deliveries');
        $stmt = $this->pdo->prepare("SELECT id, recipient, carrier, tracking_code, received_on, received_time, picked_up_on, picked_up_by, status FROM erp_deliveries
            WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL AND status <> 'inactive'
              AND (status = 'active' OR received_on >= (CURDATE() - INTERVAL 90 DAY))
            ORDER BY (status = 'active') DESC, received_on DESC, id DESC LIMIT 100");
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'recipient' => (string)$r['recipient'], 'carrier' => (string)$r['carrier'],
            'tracking_code' => $r['tracking_code'], 'received_on' => (string)$r['received_on'],
            'received_time' => $r['received_time'] !== null ? substr((string)$r['received_time'], 0, 5) : null,
            'picked_up_on' => $r['picked_up_on'], 'picked_up_by' => $r['picked_up_by'], 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Visitas avisadas pela unidade, de 30 dias atrás em diante (função visitors). */
    public function visits(array $link, ?string $today = null): array
    {
        self::assertFeature($link, 'visitors');
        $since = (new \DateTimeImmutable($today ?? date('Y-m-d')))->modify('-30 days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT id, visitor_name, visitor_document, expected_on, expected_until, notes, status FROM erp_visit_forecasts
            WHERE unit_id = :u AND condominium_id = :c AND deleted_at IS NULL AND COALESCE(expected_until, expected_on) >= :s
            ORDER BY expected_on DESC, id DESC LIMIT 100");
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 's' => $since]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'visitor_name' => (string)$r['visitor_name'],
            'visitor_document' => self::maskDocument($r['visitor_document']), 'expected_on' => (string)$r['expected_on'],
            'expected_until' => $r['expected_until'], 'notes' => (string)($r['notes'] ?? ''), 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function authorizeVisit(User $user, array $link, array $input, ?RegistryService $registry = null): array
    {
        self::assertFeature($link, 'visitors');
        $input['authorized_by'] = $link['name'];
        return ($registry ?? new RegistryService($this->pdo))->createForResident($user, 'visit_forecasts', $input, $link['condominium_id'], $link['unit_id']);
    }

    public function cancelVisit(User $user, array $link, int $id): void
    {
        self::assertFeature($link, 'visitors');
        $stmt = $this->pdo->prepare("UPDATE erp_visit_forecasts SET status = 'inactive', updated_by = :by
            WHERE id = :id AND unit_id = :u AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute(['by' => (int)$user->id, 'id' => $id, 'u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RegistryNotFound();
        }
        Audit::record('update', 'erp_visit_forecasts', $id, ['status' => 'active'], ['status' => 'inactive', 'channel' => 'resident_app']);
    }

    /** Assembleias convocadas, abertas e encerradas do condomínio (função assemblies). Rascunho e cancelada não aparecem. */
    public function assemblies(array $link): array
    {
        self::assertFeature($link, 'assemblies');
        $stmt = $this->pdo->prepare("SELECT id, kind, mode, title, location, first_call_at, second_call_at, status FROM erp_assemblies
            WHERE condominium_id = :c AND status IN ('convened','open','closed') ORDER BY first_call_at DESC, id DESC LIMIT 30");
        $stmt->execute(['c' => $link['condominium_id']]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'mode' => (string)$r['mode'],
            'title' => (string)$r['title'], 'location' => (string)$r['location'], 'first_call_at' => (string)$r['first_call_at'],
            'second_call_at' => $r['second_call_at'], 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function assembly(array $link, int $id): array
    {
        self::assertFeature($link, 'assemblies');
        $stmt = $this->pdo->prepare("SELECT id, kind, mode, title, location, first_call_at, second_call_at, status, minutes FROM erp_assemblies
            WHERE id = :id AND condominium_id = :c AND status IN ('convened','open','closed')");
        $stmt->execute(['id' => $id, 'c' => $link['condominium_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RegistryNotFound();
        }
        $items = $this->pdo->prepare('SELECT position, title, description, status, result_json FROM erp_assembly_items WHERE assembly_id = :a ORDER BY position');
        $items->execute(['a' => $id]);
        return ['id' => (int)$row['id'], 'kind' => (string)$row['kind'], 'mode' => (string)$row['mode'], 'title' => (string)$row['title'],
            'location' => (string)$row['location'], 'first_call_at' => (string)$row['first_call_at'], 'second_call_at' => $row['second_call_at'],
            'status' => (string)$row['status'], 'minutes' => $row['status'] === 'closed' ? (string)($row['minutes'] ?? '') : null,
            'items' => array_map(static function (array $r): array {
                $result = $r['status'] === 'decided' ? json_decode((string)($r['result_json'] ?? ''), true) : null;
                return ['position' => (int)$r['position'], 'title' => (string)$r['title'], 'description' => (string)($r['description'] ?? ''),
                    'status' => (string)$r['status'], 'approved' => is_array($result) && array_key_exists('approved', $result) ? (bool)$result['approved'] : null];
            }, $items->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Documentos liberados para os moradores (função documents). */
    public function documents(array $link, ?VirtualDisk $disk = null): array
    {
        self::assertFeature($link, 'documents');
        return ($disk ?? new VirtualDisk($this->pdo))->sharedWithResidents($link['condominium_id']);
    }

    /** @return array{path:string, mime:string, name:string, size:int} */
    public function documentFile(array $link, int $id, ?VirtualDisk $disk = null): array
    {
        self::assertFeature($link, 'documents');
        $file = ($disk ?? new VirtualDisk($this->pdo))->sharedFile($id, $link['condominium_id']);
        if ($file === null) {
            throw new RegistryNotFound();
        }
        Audit::record('read', 'erp_documents', $id, null, ['channel' => 'resident_app']);
        return $file;
    }

    /** Pets e veículos cadastrados na unidade (qualquer vínculo válido, só leitura). */
    public function petsVehicles(array $link): array
    {
        $pets = $this->pdo->prepare("SELECT name, species, size, breed, vaccinated FROM erp_pets WHERE unit_id = :u AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL ORDER BY name");
        $pets->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        $cars = $this->pdo->prepare("SELECT plate, kind, model, color, parking_spot FROM erp_vehicles WHERE unit_id = :u AND condominium_id = :c AND status = 'active' AND deleted_at IS NULL ORDER BY plate");
        $cars->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        return ['pets' => array_map(static fn (array $r): array => ['name' => (string)$r['name'], 'species' => (string)$r['species'], 'size' => $r['size'],
                'breed' => $r['breed'], 'vaccinated' => (int)$r['vaccinated'] === 1], $pets->fetchAll(PDO::FETCH_ASSOC)),
            'vehicles' => array_map(static fn (array $r): array => ['plate' => (string)$r['plate'], 'kind' => (string)$r['kind'], 'model' => $r['model'],
                'color' => $r['color'], 'parking_spot' => $r['parking_spot']], $cars->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Documento do visitante mascarado: só os 3 últimos caracteres (regra pura, LGPD). */
    public static function maskDocument(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        $length = mb_strlen($value);
        return $length <= 3 ? str_repeat('*', $length) : str_repeat('*', $length - 3) . mb_substr($value, -3);
    }

    private function reservationRows(array $link, string $since, bool $upcomingOnly): array
    {
        $status = $upcomingOnly ? "AND r.status IN ('active','confirmed')" : '';
        $stmt = $this->pdo->prepare("SELECT r.id, r.reserved_on, r.starts_time, r.ends_time, r.fee_cents, r.status, a.name AS area
            FROM erp_reservations r JOIN erp_common_areas a ON a.id = r.common_area_id
            WHERE r.unit_id = :u AND r.condominium_id = :c AND r.deleted_at IS NULL AND r.reserved_on >= :s {$status}
            ORDER BY r.reserved_on " . ($upcomingOnly ? 'ASC' : 'DESC') . ', r.id LIMIT 100');
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 's' => $since]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'area' => (string)$r['area'], 'reserved_on' => (string)$r['reserved_on'],
            'starts_time' => $r['starts_time'] !== null ? substr((string)$r['starts_time'], 0, 5) : null,
            'ends_time' => $r['ends_time'] !== null ? substr((string)$r['ends_time'], 0, 5) : null,
            'fee_cents' => $r['fee_cents'] !== null ? (int)$r['fee_cents'] : null, 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** A área precisa ser do condomínio do vínculo (o motor confere o condomínio da referência, isto deixa a mensagem clara). */
    private function assertAreaOf(array $link, int $areaId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_common_areas WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $areaId, 'c' => $link['condominium_id']]);
        if (!$stmt->fetchColumn()) {
            throw new \Source\Services\Erp\Registry\RegistryValidationException(['common_area_id' => 'Escolha uma área comum do seu condomínio.']);
        }
    }

    private function count(string $sql, array $link): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id']]);
        return (int)$stmt->fetchColumn();
    }
}
