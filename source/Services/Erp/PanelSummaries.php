<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Painel do condomínio (AP-002) e painel da unidade (AP-003), ADR 0054. Somente leitura, calculados
 * a cada pedido a partir dos serviços que já existem: dashboard operacional (ADR 0053), saldos
 * bancários (ADR 0024), fechamento (ADR 0031), inadimplência e acordos (ADR 0039/0040) e documentos da
 * unidade (ADR 0044). Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404).
 */
final class PanelSummaries
{
    /**
     * O painel junta dados de vários módulos. Além de erp.units.view, quem vê o painel precisa da
     * permissão de cada módulo que ele mostra (ADR 0061): sem ela, 403 em vez de vazar o dado.
     */
    public const REQUIRED = [
        'condominium' => ['erp.units.view', 'erp.bank_accounts.view', 'erp.agreements.view', 'erp.closing.view'],
        'unit' => ['erp.units.view', 'erp.residents.view', 'erp.agreements.view', 'erp.entries.view', 'erp.unit_documents.view'],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    /** @var callable(string, User): bool */
    private $can;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?callable $can = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->can = $can ?? static fn (string $permission, User $user): bool => Access::can($permission, $user);
    }

    /**
     * Permissões do painel que faltam ao usuário (regra pura).
     *
     * @param callable(string): bool $can
     * @return list<string>
     */
    public static function missing(string $panel, callable $can): array
    {
        return array_values(array_filter(self::REQUIRED[$panel] ?? [], static fn (string $permission): bool => !$can($permission)));
    }

    private function authorize(User $user, string $panel): void
    {
        if (self::missing($panel, fn (string $permission): bool => ($this->can)($permission, $user)) !== []) {
            throw new RegistryForbidden();
        }
    }

    /** Ocupação das unidades (regra pura): total, com morador ativo e vagas. */
    public static function occupancy(int $units, int $occupied): array
    {
        $occupied = min(max(0, $occupied), max(0, $units));
        return ['units' => $units, 'occupied' => $occupied, 'vacant' => max(0, $units - $occupied),
            'rate' => $units > 0 ? round($occupied / $units * 100, 1) : 0.0];
    }

    public function condominium(User $user, int $condominiumId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $this->authorize($user, 'condominium');
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
        $stmt = $this->pdo->prepare('SELECT id, name, status FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $condominiumId]);
        $condominium = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($condominium)) {
            throw new RegistryNotFound();
        }
        $units = (int)$this->scalar("SELECT COUNT(*) FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'", $condominiumId);
        $occupied = (int)$this->scalar("SELECT COUNT(DISTINCT unit_id) FROM erp_residents WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' AND unit_id IS NOT NULL", $condominiumId);
        $competence = substr($today, 0, 7);
        $balances = (new BankBalances($this->pdo, $this->scope))->forUser($user, $condominiumId);
        $delinquency = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $today);
        $closing = (new MonthClosing($this->pdo, $this->scope))->status($user, $condominiumId, $competence);
        return [
            'condominium' => ['id' => (int)$condominium['id'], 'name' => (string)$condominium['name'], 'status' => (string)$condominium['status']],
            'as_of' => $today,
            'occupancy' => self::occupancy($units, $occupied),
            'finance' => [
                'bank_balance_cents' => array_sum(array_column($balances, 'balance_cents')),
                'bank_accounts' => $balances,
                'delinquency_cents' => $delinquency['totals']['amount_cents'],
                'delinquency_charges_cents' => $delinquency['totals']['charges_cents'] ?? 0,
                'delinquent_units' => $delinquency['totals']['units'],
                'buckets' => $delinquency['totals']['buckets'],
                'competence' => $competence,
                'closing_status' => $closing['status'],
                'competence_result_cents' => $closing['summary']['result_cents'],
            ],
            'operations' => (new OperationalDashboard($this->pdo, $this->scope, $this->can))->summary($user, $condominiumId, $today),
        ];
    }

    public function unit(User $user, int $unitId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $this->authorize($user, 'unit');
        $stmt = $this->pdo->prepare('SELECT u.id, u.condominium_id, u.label, u.block, u.number, u.type, u.ideal_fraction, c.name AS condominium_name
            FROM erp_units u JOIN condominiums c ON c.id = u.condominium_id WHERE u.id = :id AND u.deleted_at IS NULL');
        $stmt->execute(['id' => $unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit) || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        $condominium = (int)$unit['condominium_id'];
        $people = $this->pdo->prepare("SELECT id, name, relationship, phone, email FROM erp_residents
            WHERE unit_id = :u AND deleted_at IS NULL AND status = 'active' ORDER BY name LIMIT 50");
        $people->execute(['u' => $unitId]);

        $delinquency = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominium, $today);
        $debts = array_values(array_filter($delinquency['units'], static fn (array $u): bool => (int)($u['unit_id'] ?? 0) === $unitId))[0] ?? null;
        $upcoming = $this->pdo->prepare("SELECT id, description, competence, due_on, amount_cents FROM erp_entries
            WHERE unit_id = :u AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on >= :t
            ORDER BY due_on, id LIMIT 12");
        $upcoming->execute(['u' => $unitId, 't' => $today]);
        $agreements = $this->pdo->prepare("SELECT id, status, total_cents, installments, first_due_on FROM erp_agreements
            WHERE unit_id = :u AND status IN ('active','breached') ORDER BY id DESC");
        $agreements->execute(['u' => $unitId]);
        $documents = (new UnitDocuments($this->pdo, $this->scope))->list($user, $condominium, ['unit_id' => $unitId]);

        $count = fn (string $sql): int => (int)$this->scalarParams($sql, ['u' => $unitId, 't' => $today]);
        return [
            'unit' => ['id' => (int)$unit['id'], 'condominium_id' => $condominium, 'condominium_name' => (string)$unit['condominium_name'],
                'label' => (string)$unit['label'], 'block' => $unit['block'], 'number' => (string)$unit['number'], 'type' => (string)$unit['type'],
                'ideal_fraction' => $unit['ideal_fraction'] !== null ? (float)$unit['ideal_fraction'] : null],
            'as_of' => $today,
            'people' => $people->fetchAll(PDO::FETCH_ASSOC),
            'finance' => [
                'overdue' => $debts['entries'] ?? [],
                'overdue_cents' => (int)($debts['amount_cents'] ?? 0),
                'charges_cents' => (int)($debts['charges_cents'] ?? 0),
                'max_days_late' => (int)($debts['max_days_late'] ?? 0),
                'upcoming' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'description' => (string)$r['description'],
                    'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'amount_cents' => (int)$r['amount_cents']], $upcoming->fetchAll(PDO::FETCH_ASSOC)),
                'agreements' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'total_cents' => (int)$r['total_cents'],
                    'installments' => (int)$r['installments'], 'first_due_on' => (string)$r['first_due_on']], $agreements->fetchAll(PDO::FETCH_ASSOC)),
            ],
            'documents' => array_slice($documents['data'], 0, 10),
            'operations' => [
                'deliveries_waiting' => $count("SELECT COUNT(*) FROM erp_deliveries WHERE unit_id = :u AND deleted_at IS NULL AND status = 'active' AND :t = :t"),
                'reservations_upcoming' => $count("SELECT COUNT(*) FROM erp_reservations WHERE unit_id = :u AND deleted_at IS NULL AND status IN ('active','confirmed') AND reserved_on >= :t"),
                'occurrences_open' => $count("SELECT COUNT(*) FROM erp_occurrences WHERE unit_id = :u AND deleted_at IS NULL AND status IN ('active','running') AND :t = :t"),
                'vehicles' => $count("SELECT COUNT(*) FROM erp_vehicles WHERE unit_id = :u AND deleted_at IS NULL AND status = 'active' AND :t = :t"),
                'pets' => $count("SELECT COUNT(*) FROM erp_pets WHERE unit_id = :u AND deleted_at IS NULL AND status = 'active' AND :t = :t"),
            ],
        ];
    }

    private function scalar(string $sql, int $condominiumId): mixed
    {
        return $this->scalarParams($sql, ['c' => $condominiumId]);
    }

    private function scalarParams(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
