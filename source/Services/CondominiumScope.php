<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\AppLogger;

/**
 * Escopo de condomínios por usuário (ADR-002: administradora única,
 * segregação por condomínio). Toda leitura ou escrita de dados de condomínio
 * deve passar por aqui no servidor.
 */
final class CondominiumScope
{
    public const ALL_PERMISSION = 'erp.condominiums.all';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * Acesso a todos os condomínios exige a permissão explícita no núcleo
     * access_* (ou o papel developer). Diferente de Access::can, NÃO usa o
     * fallback por nível legado: sem registro, o acesso global é negado.
     */
    public function hasGlobalAccess(?User $user): bool
    {
        if ($user === null || empty($user->id)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT r.slug AS role_slug, o.effect,
                EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id) AS role_allowed
            FROM access_user_roles ur
            JOIN access_roles r ON r.id = ur.role_id
            LEFT JOIN access_permissions p ON p.slug = :permission
            LEFT JOIN access_user_overrides o ON o.user_id = ur.user_id AND o.permission_id = p.id
            WHERE ur.user_id = :user LIMIT 1");
        $stmt->execute(['permission' => self::ALL_PERMISSION, 'user' => (int)$user->id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        if ($row['role_slug'] === 'developer') {
            return true;
        }
        if ($row['effect'] === 'deny') {
            return false;
        }
        return $row['effect'] === 'allow' || (bool)$row['role_allowed'];
    }

    /** @return list<int> IDs de condomínios ativos que o usuário pode acessar. */
    public function allowedIds(?User $user): array
    {
        if ($user === null || empty($user->id)) {
            return [];
        }
        if ($this->hasGlobalAccess($user)) {
            $stmt = $this->pdo->query("SELECT id FROM condominiums WHERE status = 'active' ORDER BY name");
        } else {
            $stmt = $this->pdo->prepare("SELECT c.id FROM condominiums c
                JOIN user_condominium_access a ON a.condominium_id = c.id
                WHERE a.user_id = :user AND c.status = 'active' ORDER BY c.name");
            $stmt->execute(['user' => (int)$user->id]);
        }
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id:int,name:string,document:?string,status:string}> */
    public function allowed(?User $user): array
    {
        $ids = $this->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name, document, status FROM condominiums WHERE id IN ({$placeholders}) ORDER BY name");
        $stmt->execute($ids);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'name' => (string)$row['name'],
            'document' => $row['document'] !== null ? (string)$row['document'] : null, 'status' => (string)$row['status'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lista da tela de condomínios: o mesmo escopo de allowed() mais um endereço curto
     * ("Rua, número · Cidade/UF") para diferenciar condomínios de nome parecido (pedido de 10/11/2026).
     * Fica separado de allowed() para não mudar o contrato de /api/v1/condominiums.
     *
     * @return list<array{id:int,name:string,document:?string,status:string,address:string}>
     */
    public function listing(?User $user): array
    {
        $ids = $this->allowedIds($user);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name, document, status, street, number, city, state
            FROM condominiums WHERE id IN ({$placeholders}) ORDER BY name");
        $stmt->execute($ids);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'name' => (string)$row['name'],
            'document' => $row['document'] !== null ? (string)$row['document'] : null, 'status' => (string)$row['status'],
            'address' => self::shortAddress($row),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** "Av. Brasil, 120 · Campinas/SP"; partes vazias ficam de fora; sem nada, string vazia. */
    public static function shortAddress(array $row): string
    {
        $street = trim((string)($row['street'] ?? ''));
        $number = trim((string)($row['number'] ?? ''));
        $city = trim((string)($row['city'] ?? ''));
        $state = trim((string)($row['state'] ?? ''));
        $first = $street !== '' && $number !== '' ? $street . ', ' . $number : $street;
        $second = $city !== '' && $state !== '' ? $city . '/' . mb_strtoupper($state) : $city;
        return implode(' · ', array_filter([$first, $second], static fn (string $part): bool => $part !== ''));
    }

    public function canAccess(?User $user, int $condominiumId): bool
    {
        if ($user === null || $condominiumId <= 0) {
            return false;
        }
        if ($this->hasGlobalAccess($user)) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM condominiums WHERE id = :id AND status = 'active'");
            $stmt->execute(['id' => $condominiumId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT 1 FROM user_condominium_access a
                JOIN condominiums c ON c.id = a.condominium_id
                WHERE a.user_id = :user AND a.condominium_id = :id AND c.status = 'active'");
            $stmt->execute(['user' => (int)$user->id, 'id' => $condominiumId]);
        }
        return (bool)$stmt->fetchColumn();
    }

    /** Lança exceção e registra log quando o acesso é negado. */
    public function assertAccess(?User $user, int $condominiumId): void
    {
        if (!$this->canAccess($user, $condominiumId)) {
            AppLogger::log('warning', 'Acesso negado a condomínio fora do escopo', [
                'event_type' => 'authorization_denied', 'code' => 'CONDOMINIUM_SCOPE_DENIED',
                'user_id' => $user->id ?? null, 'condominium_id' => $condominiumId, 'status' => 'resolved',
            ], 'authorization');
            throw new CondominiumAccessDenied($condominiumId);
        }
    }

    public function grant(int $userId, int $condominiumId, ?int $grantedBy = null): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO user_condominium_access (user_id, condominium_id, granted_by) VALUES (:user, :condo, :by)');
        $stmt->execute(['user' => $userId, 'condo' => $condominiumId, 'by' => $grantedBy]);
    }

    public function revoke(int $userId, int $condominiumId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_condominium_access WHERE user_id = :user AND condominium_id = :condo');
        $stmt->execute(['user' => $userId, 'condo' => $condominiumId]);
    }
}
