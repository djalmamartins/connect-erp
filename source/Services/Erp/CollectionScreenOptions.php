<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Opções das telas de cobrança (ADR 0075 e 0080), só leitura e com o escopo conferido:
 * - lawyers(): advogados ativos do cadastro (AP-016) para o caso judicial (AP-039);
 * - fineOrigins(): ocorrências e reservas recentes do condomínio, que podem motivar a multa avulsa
 *   (AP-027). A gravação confere de novo a unidade e o condomínio (EntryShortcuts).
 */
final class CollectionScreenOptions
{
    /** Quantas ocorrências e reservas a escolha da origem mostra (as mais recentes). */
    public const ORIGIN_LIMIT = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<array{id:int, name:string, oab:string}> */
    public function lawyers(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'oab' => (string)$r['oab']],
            $this->pdo->query("SELECT id, name, oab FROM erp_lawyers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name, id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Ocorrências (do condomínio, com ou sem unidade) e reservas (da unidade) do condomínio, das mais
     * recentes às mais antigas. Fora do escopo, listas vazias.
     *
     * @return array{occurrences: list<array>, reservations: list<array>}
     */
    public function fineOrigins(User $user, int $condominiumId): array
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            return ['occurrences' => [], 'reservations' => []];
        }
        $stmt = $this->pdo->prepare('SELECT o.id, o.unit_id, o.subject, o.occurred_on FROM erp_occurrences o
            WHERE o.condominium_id = :c AND o.deleted_at IS NULL ORDER BY o.occurred_on DESC, o.id DESC LIMIT ' . self::ORIGIN_LIMIT);
        $stmt->execute(['c' => $condominiumId]);
        $occurrences = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'unit_id' => $r['unit_id'] !== null ? (int)$r['unit_id'] : null,
            'subject' => (string)$r['subject'], 'occurred_on' => (string)$r['occurred_on']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare('SELECT r.id, r.unit_id, r.reserved_on, a.name AS area FROM erp_reservations r LEFT JOIN erp_common_areas a ON a.id = r.common_area_id
            WHERE r.condominium_id = :c AND r.deleted_at IS NULL ORDER BY r.reserved_on DESC, r.id DESC LIMIT ' . self::ORIGIN_LIMIT);
        $stmt->execute(['c' => $condominiumId]);
        $reservations = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'unit_id' => (int)$r['unit_id'],
            'area' => (string)($r['area'] ?? 'Área comum'), 'reserved_on' => (string)$r['reserved_on']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['occurrences' => $occurrences, 'reservations' => $reservations];
    }
}
