<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\ResidentPortalPermissions;

/**
 * Vínculos do morador com as unidades (ADR 0302). A conta do aplicativo nasce no aceite do convite
 * (ADR 0092), que grava erp_residents.user_id. Um vínculo vale quando o cadastro do morador está ativo,
 * não foi excluído, tem unidade ativa e está dentro do período (início e fim). O condomínio precisa
 * estar ativo. Quem tem mais de uma unidade escolhe qual está vendo; sem escolha, vale a primeira.
 *
 * Tudo aqui falha fechado: sem vínculo válido o aplicativo não abre, e cada função só aparece se a
 * matriz do portal (ResidentPortalPermissions, AP-153) estiver ligada no condomínio do vínculo.
 */
final class ResidentLinks
{
    /** Quem vê o financeiro da unidade: proprietário, inquilino e responsável financeiro. */
    public const FINANCIAL_RELATIONSHIPS = ['owner', 'tenant', 'financial'];
    public const RELATIONSHIPS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** O vínculo vale hoje? (regra pura) */
    public static function activeOn(?string $startsOn, ?string $endsOn, string $today): bool
    {
        return ($startsOn === null || $startsOn === '' || $startsOn <= $today) && ($endsOn === null || $endsOn === '' || $endsOn >= $today);
    }

    /**
     * Escolhe o vínculo pedido entre os válidos; pedido inválido ou ausente = o primeiro (regra pura).
     *
     * @param list<array{resident_id:int}> $links
     */
    public static function pick(array $links, mixed $wanted): ?array
    {
        $id = filter_var($wanted, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        foreach ($links as $link) {
            if ($id !== false && $id !== null && $link['resident_id'] === $id) {
                return $link;
            }
        }
        return $links[0] ?? null;
    }

    /** Funções que o vínculo enxerga: matriz ligada e, no financeiro, o tipo de vínculo (regra pura). */
    public static function visibleFeatures(array $enabled, string $relationship): array
    {
        $out = [];
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            if (empty($enabled[$feature])) {
                continue;
            }
            if ($feature === 'boletos' && !in_array($relationship, self::FINANCIAL_RELATIONSHIPS, true)) {
                continue;
            }
            $out[] = $feature;
        }
        return $out;
    }

    /**
     * Vínculos válidos da conta, com as funções ligadas de cada um.
     *
     * @return list<array{resident_id:int, name:string, relationship:string, relationship_label:string, condominium_id:int,
     *     condominium_name:string, unit_id:int, unit_label:string, features:list<string>}>
     */
    public function forUser(?User $user, ?string $today = null): array
    {
        if ($user === null || (int)($user->id ?? 0) < 1) {
            return [];
        }
        $today ??= date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT r.id, r.name, r.relationship, r.starts_on, r.ends_on, r.condominium_id, c.name AS condominium_name,
                u.id AS unit_id, u.label AS unit_label
            FROM erp_residents r
            JOIN condominiums c ON c.id = r.condominium_id
            JOIN erp_units u ON u.id = r.unit_id AND u.condominium_id = r.condominium_id
            WHERE r.user_id = :u AND r.deleted_at IS NULL AND r.status = 'active'
              AND u.deleted_at IS NULL AND u.status = 'active' AND c.status = 'active'
            ORDER BY c.name, u.label, r.id");
        $stmt->execute(['u' => (int)$user->id]);
        $links = [];
        $features = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!self::activeOn($row['starts_on'] !== null ? (string)$row['starts_on'] : null, $row['ends_on'] !== null ? (string)$row['ends_on'] : null, $today)) {
                continue;
            }
            $condominium = (int)$row['condominium_id'];
            $features[$condominium] ??= $this->enabled($condominium);
            $links[] = [
                'resident_id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'relationship' => (string)$row['relationship'],
                'relationship_label' => self::RELATIONSHIPS[(string)$row['relationship']] ?? (string)$row['relationship'],
                'condominium_id' => $condominium,
                'condominium_name' => (string)$row['condominium_name'],
                'unit_id' => (int)$row['unit_id'],
                'unit_label' => (string)$row['unit_label'],
                'features' => self::visibleFeatures($features[$condominium], (string)$row['relationship']),
            ];
        }
        return $links;
    }

    /** @return array<string, bool> */
    private function enabled(int $condominiumId): array
    {
        $out = [];
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            $out[$feature] = ResidentPortalPermissions::enabled($this->pdo, $condominiumId, $feature);
        }
        return $out;
    }
}
