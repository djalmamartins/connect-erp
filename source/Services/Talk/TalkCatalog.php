<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketError;

/**
 * Dados dos condomínios para o Connect Talk (ADR 0624, escopo condominiums.read). Só o que o atendimento por
 * WhatsApp precisa e nada de pessoa: nome e endereço do condomínio, unidades (bloco e número), áreas comuns que
 * aceitam reserva (livre ou com aprovação; bloqueada e sem reserva ficam de fora) e comunicados publicados para todos. Sem CNPJ, sem dados bancários, sem síndico, sem moradores.
 * Só condomínios ativos do escopo do usuário de integração; fora dele = 404, igual a um id que não existe.
 */
final class TalkCatalog
{
    public const PAGE_SIZE = 50;
    public const UNITS_PAGE_SIZE = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Página (1..) e tamanho viram LIMIT/OFFSET seguros (regra pura). @return array{0:int,1:int} */
    public static function page(mixed $page, int $size): array
    {
        $n = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $n = $n === false ? 1 : (int)$n;
        return [$n, ($n - 1) * $size];
    }

    /** @return array{data: list<array>, meta: array{page:int, per_page:int, total:int}} */
    public function condominiums(?User $agent, mixed $page = 1): array
    {
        $ids = $this->activeIds($agent);
        [$n, $offset] = self::page($page, self::PAGE_SIZE);
        if ($ids === []) {
            return ['data' => [], 'meta' => ['page' => $n, 'per_page' => self::PAGE_SIZE, 'total' => 0]];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name, district, city, state FROM condominiums WHERE id IN ({$marks})
            ORDER BY name, id LIMIT " . self::PAGE_SIZE . ' OFFSET ' . $offset);
        $stmt->execute($ids);
        $rows = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'],
            'district' => self::text($r['district']), 'city' => self::text($r['city']), 'state' => self::text($r['state'])], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'meta' => ['page' => $n, 'per_page' => self::PAGE_SIZE, 'total' => count($ids)]];
    }

    /** Condomínio com endereço e áreas comuns que aceitam reserva. */
    public function condominium(?User $agent, int $id): array
    {
        $this->assertScope($agent, $id);
        $stmt = $this->pdo->prepare('SELECT id, name, zip_code, street, number, complement, district, city, state FROM condominiums WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new TicketError('id', 'Condomínio não encontrado.', 404);
        }
        $areas = $this->pdo->prepare("SELECT id, name, booking_mode, capacity, booking_fee_cents, opening_time, closing_time, rules
            FROM erp_common_areas WHERE condominium_id = ? AND status = 'active' AND deleted_at IS NULL AND booking_mode IN ('approval', 'free') ORDER BY name, id");
        $areas->execute([$id]);
        return [
            'id' => (int)$row['id'], 'name' => (string)$row['name'],
            'address' => ['zip_code' => self::text($row['zip_code']), 'street' => self::text($row['street']), 'number' => self::text($row['number']),
                'complement' => self::text($row['complement']), 'district' => self::text($row['district']), 'city' => self::text($row['city']),
                'state' => self::text($row['state']), 'short' => CondominiumScope::shortAddress($row)],
            'common_areas' => array_map(static fn (array $a): array => ['id' => (int)$a['id'], 'name' => (string)$a['name'],
                'booking_mode' => (string)$a['booking_mode'], 'capacity' => $a['capacity'] !== null ? (int)$a['capacity'] : null,
                'fee_cents' => $a['booking_fee_cents'] !== null ? (int)$a['booking_fee_cents'] : null,
                'opening_time' => $a['opening_time'] !== null ? substr((string)$a['opening_time'], 0, 5) : null,
                'closing_time' => $a['closing_time'] !== null ? substr((string)$a['closing_time'], 0, 5) : null,
                'rules' => (string)($a['rules'] ?? '')], $areas->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** Unidades ativas: só identificação (bloco, número, tipo). Nunca quem mora. */
    public function units(?User $agent, int $id, mixed $page = 1): array
    {
        $this->assertScope($agent, $id);
        [$n, $offset] = self::page($page, self::UNITS_PAGE_SIZE);
        $total = $this->pdo->prepare("SELECT COUNT(*) FROM erp_units WHERE condominium_id = ? AND status = 'active' AND deleted_at IS NULL");
        $total->execute([$id]);
        $stmt = $this->pdo->prepare("SELECT id, block, number, label, type FROM erp_units WHERE condominium_id = ? AND status = 'active' AND deleted_at IS NULL
            ORDER BY block, LENGTH(number), number, id LIMIT " . self::UNITS_PAGE_SIZE . ' OFFSET ' . $offset);
        $stmt->execute([$id]);
        $rows = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'block' => self::text($r['block']), 'number' => (string)$r['number'],
            'label' => (string)$r['label'], 'type' => (string)$r['type']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'meta' => ['page' => $n, 'per_page' => self::UNITS_PAGE_SIZE, 'total' => (int)$total->fetchColumn()]];
    }

    /**
     * Comunicados publicados para TODO o condomínio (audiência "all", já liberados, não cancelados), mais novos
     * primeiro. Os enviados só a algumas unidades ficam de fora: são da etapa 3, por morador.
     */
    public function announcements(?User $agent, int $id, mixed $page = 1): array
    {
        $this->assertScope($agent, $id);
        [$n, $offset] = self::page($page, self::PAGE_SIZE);
        $where = "condominium_id = ? AND kind = 'general' AND status = 'active' AND audience = 'all' AND send_at <= NOW()";
        $total = $this->pdo->prepare("SELECT COUNT(*) FROM erp_announcements WHERE {$where}");
        $total->execute([$id]);
        $stmt = $this->pdo->prepare("SELECT id, subject, body, send_at FROM erp_announcements WHERE {$where}
            ORDER BY send_at DESC, id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . $offset);
        $stmt->execute([$id]);
        $rows = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'subject' => (string)$r['subject'],
            'body' => \Source\Support\RichText::plain((string)($r['body'] ?? '')), 'published_at' => (string)$r['send_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'meta' => ['page' => $n, 'per_page' => self::PAGE_SIZE, 'total' => (int)$total->fetchColumn()]];
    }

    /** @return list<int> condomínios ativos do escopo */
    private function activeIds(?User $agent): array
    {
        $allowed = $agent !== null ? $this->scope->allowedIds($agent) : [];
        if ($allowed === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM condominiums WHERE id IN ({$marks}) AND status = 'active'");
        $stmt->execute($allowed);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertScope(?User $agent, int $id): void
    {
        if ($id <= 0 || !in_array($id, $this->activeIds($agent), true)) {
            throw new TicketError('id', 'Condomínio não encontrado.', 404);
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = $value !== null ? trim((string)$value) : '';
        return $value !== '' ? $value : null;
    }
}
