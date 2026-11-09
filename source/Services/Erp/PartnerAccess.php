<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Imobiliárias parceiras (AP-148, ADR 0090). Regras conservadoras:
 * - o único papel que este serviço concede é PARTNER_ROLE (erp_partner), que só tem erp.access e
 *   erp.partner.view. Nunca developer, super_admin ou outro papel: não há parâmetro de papel;
 * - só vira parceiro o usuário comum (users.level <= MAX_USER_LEVEL) que ainda não tem papel nem acesso a
 *   condomínio. Assim, vincular nunca rebaixa nem promove ninguém;
 * - quem concede só concede condomínio do próprio escopo. Desvincular tira o papel, todo o acesso a
 *   condomínios e o vínculo. Sem papel, o usuário fica sem acesso (fail-closed);
 * - a conta do usuário é criada pelo fluxo de usuários que já existe; aqui não se cria login nem senha;
 * - consulta do parceiro (view): unidades e moradores dos condomínios concedidos, com e-mail, telefone e
 *   documento mascarados; nada de financeiro, inadimplência ou documentos.
 * Toda mudança vai para a auditoria.
 */
final class PartnerAccess
{
    public const PARTNER_ROLE = 'erp_partner';
    public const MAX_USER_LEVEL = 1;
    public const MAX_VIEW_ROWS = 5000;
    public const MAX_AGENCIES = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** CNPJ (regra pura): 14 dígitos com os dois verificadores. */
    public static function validCnpj(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return null;
        }
        foreach ([12, 13] as $length) {
            $sum = 0;
            $weight = $length - 7;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int)$digits[$i] * $weight--;
                if ($weight < 2) {
                    $weight = 9;
                }
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int)$digits[$length] !== $check) {
                return null;
            }
        }
        return $digits;
    }

    /** @param array{name?:mixed, document?:mixed, email?:mixed, phone?:mixed} $input */
    public function createAgency(User $admin, array $input): array
    {
        $name = Announcements::line($input['name'] ?? null, 3, 160, 'o nome da imobiliária');
        $document = self::validCnpj(is_string($input['document'] ?? null) ? $input['document'] : '') ?? throw new InvalidArgumentException('CNPJ inválido.');
        $email = $input['email'] ?? null;
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        $phone = preg_replace('/\D/', '', (string)($input['phone'] ?? '')) ?: null;
        if ($phone !== null && (strlen($phone) < 10 || strlen($phone) > 13)) {
            throw new InvalidArgumentException('Telefone inválido (DDD e número).');
        }
        try {
            $this->pdo->prepare('INSERT INTO erp_partner_agencies (name, document, email, phone, created_by, updated_by) VALUES (:n, :d, :e, :p, :u, :u2)')
                ->execute(['n' => $name, 'd' => $document, 'e' => $email ?: null, 'p' => $phone, 'u' => (int)$admin->id, 'u2' => (int)$admin->id]);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new DomainException('Já existe imobiliária com este CNPJ.');
            }
            throw $error;
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_partner_agencies', $id, null, ['name' => $name, 'document' => CollectionReports::maskDocument($document)]);
        return $this->agency($id);
    }

    /** @return list<array> imobiliárias com os usuários e os condomínios de cada um (só os do escopo de quem consulta). */
    public function listAgencies(User $admin): array
    {
        $allowed = array_values(array_filter(array_map('intval', $this->scope->allowedIds($admin)), static fn (int $i): bool => $i > 0));
        $agencies = $this->pdo->query('SELECT * FROM erp_partner_agencies ORDER BY name, id LIMIT ' . self::MAX_AGENCIES)->fetchAll(PDO::FETCH_ASSOC);
        // ADR 0094: só os usuários das imobiliárias listadas e só as concessões do escopo de quem consulta, com teto.
        $agencyIds = array_map(static fn (array $a): int => (int)$a['id'], $agencies);
        $users = $agencyIds === [] ? [] : $this->pdo->query('SELECT pu.agency_id, pu.user_id, u.first_name, u.last_name, u.email FROM erp_partner_agency_users pu
            JOIN users u ON u.id = pu.user_id WHERE pu.agency_id IN (' . implode(',', $agencyIds) . ') ORDER BY pu.agency_id, u.first_name LIMIT ' . self::MAX_VIEW_ROWS)->fetchAll(PDO::FETCH_ASSOC);
        $userIds = array_map(static fn (array $u): int => (int)$u['user_id'], $users);
        $grants = $userIds === [] || $allowed === [] ? [] : $this->pdo->query('SELECT user_id, condominium_id FROM user_condominium_access
            WHERE user_id IN (' . implode(',', $userIds) . ') AND condominium_id IN (' . implode(',', $allowed) . ') LIMIT ' . self::MAX_VIEW_ROWS)->fetchAll(PDO::FETCH_ASSOC);
        $byUser = [];
        foreach ($grants as $g) {
            $byUser[(int)$g['user_id']][] = (int)$g['condominium_id'];
        }
        $byAgency = [];
        foreach ($users as $u) {
            $byAgency[(int)$u['agency_id']][] = ['user_id' => (int)$u['user_id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']),
                'email' => RegistryReports::maskEmail($u['email']), 'condominium_ids' => $byUser[(int)$u['user_id']] ?? []];
        }
        return array_map(fn (array $a): array => $this->presentAgency($a) + ['users' => $byAgency[(int)$a['id']] ?? []], $agencies);
    }

    /** Vincula um usuário comum, sem papel e sem acesso, à imobiliária, com o papel erp_partner. */
    public function linkUser(User $admin, int $agencyId, int $userId): array
    {
        $agency = $this->agency($agencyId);
        if ($agency['status'] !== 'active') {
            throw new DomainException('Imobiliária inativa.');
        }
        if ($userId === (int)$admin->id) {
            throw new DomainException('Você não pode vincular a própria conta.');
        }
        $role = $this->partnerRoleId();
        $this->pdo->beginTransaction();
        try {
            // Usuário travado durante a checagem e o vínculo (ADR 0111): outra mudança de papel ou de nível
            // na mesma conta espera, e a checagem vale até o commit.
            $stmt = $this->pdo->prepare('SELECT id, level FROM users WHERE id = :u FOR UPDATE');
            $stmt->execute(['u' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                throw new InvalidArgumentException('Usuário não encontrado.');
            }
            if ((int)$user['level'] > self::MAX_USER_LEVEL) {
                throw new DomainException('Só um usuário comum pode ser parceiro: este usuário tem nível administrativo.');
            }
            $check = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM access_user_roles WHERE user_id = :u1) AS roles,
                (SELECT COUNT(*) FROM user_condominium_access WHERE user_id = :u2) AS grants, (SELECT COUNT(*) FROM access_user_overrides WHERE user_id = :u3) AS overrides');
            $check->execute(['u1' => $userId, 'u2' => $userId, 'u3' => $userId]);
            $state = $check->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($state['roles'] ?? 0) > 0 || (int)($state['grants'] ?? 0) > 0 || (int)($state['overrides'] ?? 0) > 0) {
                throw new DomainException('O usuário já tem papel, acesso a condomínio ou permissão própria: use uma conta nova para o parceiro.');
            }
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, :by)')->execute(['u' => $userId, 'r' => $role, 'by' => (int)$admin->id]);
            $this->pdo->prepare('INSERT INTO erp_partner_agency_users (user_id, agency_id, linked_by) VALUES (:u, :a, :by)')->execute(['u' => $userId, 'a' => $agencyId, 'by' => (int)$admin->id]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_partner_agency_users', $userId, null, ['agency_id' => $agencyId, 'role' => self::PARTNER_ROLE]);
        return ['agency_id' => $agencyId, 'user_id' => $userId, 'role' => self::PARTNER_ROLE];
    }

    /** Concede um condomínio do escopo de quem concede a um usuário parceiro. */
    public function grant(User $admin, int $userId, int $condominiumId): array
    {
        $this->scope->assertAccess($admin, $condominiumId);
        $this->locked(function () use ($admin, $userId, $condominiumId): void {
            $this->assertPartner($userId, true);
            $this->scope->grant($userId, $condominiumId, (int)$admin->id);
        });
        Audit::record('create', 'user_condominium_access', $userId, null, ['condominium_id' => $condominiumId, 'role' => self::PARTNER_ROLE]);
        return ['user_id' => $userId, 'condominium_id' => $condominiumId];
    }

    /** Revoga um condomínio do usuário parceiro (só condomínio do escopo de quem revoga). */
    public function revoke(User $admin, int $userId, int $condominiumId): array
    {
        $this->scope->assertAccess($admin, $condominiumId);
        $this->locked(function () use ($userId, $condominiumId): void {
            $this->assertPartner($userId, true);
            $this->scope->revoke($userId, $condominiumId);
        });
        Audit::record('delete', 'user_condominium_access', $userId, ['condominium_id' => $condominiumId, 'role' => self::PARTNER_ROLE], null);
        return ['user_id' => $userId, 'condominium_id' => $condominiumId, 'revoked' => true];
    }

    /** Desvincula: tira o papel, todo o acesso a condomínios e o vínculo. O usuário fica sem acesso. */
    public function unlinkUser(User $admin, int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $agency = $this->assertPartner($userId, true);
            $this->assertScopeOverGrants($admin, [$userId]);
            $this->pdo->prepare('DELETE FROM user_condominium_access WHERE user_id = :u')->execute(['u' => $userId]);
            $this->pdo->prepare('DELETE ur FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id WHERE ur.user_id = :u AND r.slug = :s')
                ->execute(['u' => $userId, 's' => self::PARTNER_ROLE]);
            $this->pdo->prepare('DELETE FROM erp_partner_agency_users WHERE user_id = :u')->execute(['u' => $userId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('delete', 'erp_partner_agency_users', $userId, ['agency_id' => $agency, 'role' => self::PARTNER_ROLE], null);
        return ['user_id' => $userId, 'unlinked' => true];
    }

    /** Inativa a imobiliária e desvincula todos os usuários dela. */
    public function deactivateAgency(User $admin, int $agencyId): array
    {
        $this->agency($agencyId);
        $stmt = $this->pdo->prepare('SELECT user_id FROM erp_partner_agency_users WHERE agency_id = :a');
        $stmt->execute(['a' => $agencyId]);
        $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // ADR 0094: confere o escopo de todos antes de desvincular o primeiro, para não parar no meio.
        $this->assertScopeOverGrants($admin, $userIds);
        foreach ($userIds as $userId) {
            $this->unlinkUser($admin, $userId);
        }
        $this->pdo->prepare("UPDATE erp_partner_agencies SET status = 'inactive', updated_by = :u WHERE id = :id")->execute(['u' => (int)$admin->id, 'id' => $agencyId]);
        Audit::record('update', 'erp_partner_agencies', $agencyId, ['status' => 'active'], ['status' => 'inactive']);
        return $this->agency($agencyId);
    }

    /**
     * Consulta do parceiro: unidades e moradores de um condomínio concedido, com contato mascarado.
     * Exige que o usuário seja parceiro vinculado a uma imobiliária ativa, além do escopo.
     */
    public function view(User $partner, int $condominiumId): array
    {
        $stmt = $this->pdo->prepare("SELECT a.status FROM erp_partner_agency_users pu JOIN erp_partner_agencies a ON a.id = pu.agency_id WHERE pu.user_id = :u");
        $stmt->execute(['u' => (int)$partner->id]);
        if ($stmt->fetchColumn() !== 'active') {
            throw new RegistryNotFound();
        }
        $this->scope->assertAccess($partner, $condominiumId);
        $units = $this->pdo->prepare("SELECT id, label, type, status FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL ORDER BY label LIMIT " . self::MAX_VIEW_ROWS);
        $units->execute(['c' => $condominiumId]);
        $people = $this->pdo->prepare("SELECT unit_id, name, relationship, email, phone, document FROM erp_residents WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'
            ORDER BY name LIMIT " . self::MAX_VIEW_ROWS);
        $people->execute(['c' => $condominiumId]);
        return [
            'condominium_id' => $condominiumId,
            'units' => array_map(static fn (array $u): array => ['id' => (int)$u['id'], 'label' => (string)$u['label'], 'type' => $u['type'], 'status' => (string)$u['status']],
                $units->fetchAll(PDO::FETCH_ASSOC)),
            'residents' => array_map(static fn (array $p): array => ['unit_id' => $p['unit_id'] === null ? null : (int)$p['unit_id'], 'name' => (string)$p['name'],
                'relationship' => $p['relationship'], 'email' => RegistryReports::maskEmail($p['email']), 'phone' => RegistryReports::maskPhone($p['phone']),
                'document' => CollectionReports::maskDocument($p['document'])], $people->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Roda numa transação (ADR 0111): conceder ou revogar ao mesmo tempo que se desvincula não deixa acesso
     * para quem já não é parceiro, porque os dois travam o vínculo do usuário.
     */
    private function locked(callable $action): void
    {
        $this->pdo->beginTransaction();
        try {
            $action();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * ADR 0094: quem desvincula precisa ver todos os condomínios concedidos aos parceiros; senão
     * CondominiumAccessDenied (404), sem mexer em nada.
     *
     * @param list<int> $userIds
     */
    private function assertScopeOverGrants(User $admin, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $granted = $this->pdo->query('SELECT DISTINCT condominium_id FROM user_condominium_access WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        foreach ($granted->fetchAll(PDO::FETCH_COLUMN) as $condominiumId) {
            $this->scope->assertAccess($admin, (int)$condominiumId);
        }
    }

    /** @return int id da imobiliária do usuário parceiro; $lock trava o vínculo até o fim da transação */
    private function assertPartner(int $userId, bool $lock = false): int
    {
        $stmt = $this->pdo->prepare('SELECT pu.agency_id FROM erp_partner_agency_users pu JOIN access_user_roles ur ON ur.user_id = pu.user_id
            JOIN access_roles r ON r.id = ur.role_id AND r.slug = :s WHERE pu.user_id = :u' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['s' => self::PARTNER_ROLE, 'u' => $userId]);
        $agency = $stmt->fetchColumn();
        if ($agency === false) {
            throw new DomainException('O usuário não é parceiro vinculado: só parceiros recebem ou perdem acesso por aqui.');
        }
        return (int)$agency;
    }

    private function partnerRoleId(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM access_roles WHERE slug = :s');
        $stmt->execute(['s' => self::PARTNER_ROLE]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new DomainException('O papel erp_partner não está instalado (migration 20261108_0544).');
        }
        return (int)$id;
    }

    private function agency(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_partner_agencies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RegistryNotFound();
        }
        return $this->presentAgency($row);
    }

    private function presentAgency(array $a): array
    {
        return ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'document' => CollectionReports::maskDocument($a['document']),
            'email' => RegistryReports::maskEmail($a['email']), 'phone' => RegistryReports::maskPhone($a['phone']), 'status' => (string)$a['status']];
    }
}
