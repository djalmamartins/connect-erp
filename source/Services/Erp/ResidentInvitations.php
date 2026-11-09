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
 * Convite de morador (AP-152, ADR 0090). Aqui não se cria conta, login, senha nem 2FA:
 * - invite(): para cada morador ativo com e-mail, um convite e o evento EVENT na outbox (ADR 0054), que
 *   entrega só em log ou caixa de teste fora de produção. O token (32 bytes aleatórios) nasce na entrega,
 *   por issueToken(), e só o sha256 fica na tabela. Validade de VALID_DAYS dias e uso único. Um convite pendente por morador; um novo
 *   convite revoga o anterior. Teto de MAX_PER_DAY convites por condomínio em 24 horas;
 * - lookup() e accept(): para o fluxo de cadastro do Studio (outra frente), que cria a conta e chama
 *   accept() com o id do usuário novo. O aceite é atômico: só um pedido consome o token;
 * - revoke(): cancela o convite pendente.
 * Toda mudança vai para a auditoria, sem o token e sem o e-mail.
 */
final class ResidentInvitations
{
    public const EVENT = 'resident.invitation';
    public const VALID_DAYS = 7;
    public const MAX_PER_DAY = 200;
    public const MAX_BATCH = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Token novo (regra pura): 64 caracteres hexadecimais de random_bytes. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Hash guardado (regra pura). Formato inválido = null, sem consulta ao banco. */
    public static function hashOf(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? hash('sha256', $token) : null;
    }

    /**
     * Convida os moradores escolhidos (ids) de um condomínio.
     *
     * @param list<mixed> $residentIds
     * @return array{invited:int, skipped:list<array{resident_id:int, reason:string}>}
     */
    public function invite(User $admin, int $condominiumId, array $residentIds, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->scope->assertAccess($admin, $condominiumId);
        if (!in_array(self::EVENT, NotificationOutbox::EVENTS, true)) {
            throw new DomainException('O aviso de convite ainda não está registrado na outbox: convite indisponível até o alinhamento (ADR 0090).');
        }
        $ids = Announcements::unitIds($residentIds) ?? [];
        if (count($ids) > self::MAX_BATCH) {
            throw new InvalidArgumentException(sprintf('Convide até %d moradores por vez.', self::MAX_BATCH));
        }
        $stmt = $this->pdo->prepare("SELECT id, email, document FROM erp_residents WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' AND id IN (" . implode(',', array_map('intval', $ids)) . ')');
        $stmt->execute(['c' => $condominiumId]);
        $found = [];
        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int)$row['id']] = (string)($row['email'] ?? '');
            $documents[(int)$row['id']] = $row['document'];
        }
        InvitationRules::assertInvitable($documents);   // ADR 0093: sem CPF/CNPJ válido no cadastro, nada de convite (422)
        $skipped = [];
        $invited = 0;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM condominiums WHERE id = :c FOR UPDATE')->execute(['c' => $condominiumId]);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM erp_resident_invitations WHERE condominium_id = :c AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $count->execute(['c' => $condominiumId]);
            $sent = (int)$count->fetchColumn();
            $outbox = new NotificationOutbox($this->pdo);
            foreach ($ids as $id) {
                if (!isset($found[$id])) {
                    $skipped[] = ['resident_id' => $id, 'reason' => 'not_found'];
                    continue;
                }
                if (filter_var($found[$id], FILTER_VALIDATE_EMAIL) === false) {
                    $skipped[] = ['resident_id' => $id, 'reason' => 'no_email'];
                    continue;
                }
                if ($sent >= self::MAX_PER_DAY) {
                    $skipped[] = ['resident_id' => $id, 'reason' => 'daily_limit'];
                    continue;
                }
                $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'revoked', revoked_at = :t WHERE resident_id = :r AND status = 'pending'")
                    ->execute(['t' => $now->format('Y-m-d H:i:s'), 'r' => $id]);
                // Hash de um valor que ninguém recebe: o convite só fica utilizável quando a entrega chama issueToken().
                $this->pdo->prepare('INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, expires_at, created_by) VALUES (:c, :r, :h, :e, :u)')
                    ->execute(['c' => $condominiumId, 'r' => $id, 'h' => hash('sha256', self::newToken()), 'e' => $now->modify('+' . self::VALID_DAYS . ' days')->format('Y-m-d H:i:s'),
                        'u' => (int)$admin->id]);
                $invitation = (int)$this->pdo->lastInsertId();
                $outbox->record(self::EVENT, "resident.invitation:{$invitation}", $condominiumId, 'erp_resident_invitations', $invitation,
                    ['invitation_id' => $invitation, 'resident_id' => $id, 'expires_days' => self::VALID_DAYS], (int)$admin->id);
                $invited++;
                $sent++;
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_resident_invitations', 0, null, ['condominium_id' => $condominiumId, 'invited' => $invited, 'skipped' => count($skipped)]);
        return ['invited' => $invited, 'skipped' => $skipped];
    }

    /**
     * Para a entrega (NotificationDispatcher, no alinhamento): gera o token na hora do envio, troca o hash e
     * devolve o token para o link do e-mail. O token nunca passa pela outbox (ADR 0054 tira chaves "token").
     * Cada chamada invalida o token anterior. Convite que não está pendente ou venceu = null.
     */
    public function issueToken(int $invitationId, ?\DateTimeImmutable $now = null): ?string
    {
        $token = self::newToken();
        $stmt = $this->pdo->prepare("UPDATE erp_resident_invitations SET token_hash = :h WHERE id = :id AND status = 'pending' AND expires_at > :n");
        $stmt->execute(['h' => hash('sha256', $token), 'id' => $invitationId, 'n' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return $stmt->rowCount() === 1 ? $token : null;
    }

    /** Convite pendente e válido para o token, ou null. Não revela se o token existiu. */
    public function lookup(mixed $token, ?\DateTimeImmutable $now = null): ?array
    {
        $hash = self::hashOf($token);
        if ($hash === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, resident_id, expires_at FROM erp_resident_invitations WHERE token_hash = :h AND status = 'pending' AND expires_at > :n");
        $stmt->execute(['h' => $hash, 'n' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'resident_id' => (int)$row['resident_id'], 'expires_at' => (string)$row['expires_at']] : null;
    }

    /** Consome o token uma vez, para o usuário criado pelo fluxo do Studio. Falso = vencido, usado, revogado ou inexistente. */
    public function accept(mixed $token, int $userId, ?\DateTimeImmutable $now = null): bool
    {
        $hash = self::hashOf($token);
        if ($hash === null || $userId < 1) {
            return false;
        }
        $at = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'accepted', accepted_at = :t, accepted_user_id = :u WHERE token_hash = :h AND status = 'pending' AND expires_at > :n");
        $stmt->execute(['t' => $at, 'u' => $userId, 'h' => $hash, 'n' => $at]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $id = $this->pdo->prepare('SELECT id FROM erp_resident_invitations WHERE token_hash = :h');
        $id->execute(['h' => $hash]);
        Audit::record('update', 'erp_resident_invitations', (int)$id->fetchColumn(), ['status' => 'pending'], ['status' => 'accepted', 'user_id' => $userId]);
        return true;
    }

    public function revoke(User $admin, int $invitationId): array
    {
        $stmt = $this->pdo->prepare('SELECT condominium_id, status FROM erp_resident_invitations WHERE id = :id');
        $stmt->execute(['id' => $invitationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($admin, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        $update = $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'revoked', revoked_at = NOW() WHERE id = :id AND status = 'pending'");
        $update->execute(['id' => $invitationId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Só o convite pendente pode ser revogado.');
        }
        Audit::record('update', 'erp_resident_invitations', $invitationId, ['status' => 'pending'], ['status' => 'revoked']);
        return ['id' => $invitationId, 'status' => 'revoked'];
    }

    /** @return list<array> convites do condomínio, sem token, com o e-mail mascarado. */
    public function list(User $admin, int $condominiumId): array
    {
        $this->scope->assertAccess($admin, $condominiumId);
        $stmt = $this->pdo->prepare("SELECT i.id, i.resident_id, r.name, r.email, i.status, i.expires_at, i.accepted_at, i.created_at FROM erp_resident_invitations i
            JOIN erp_residents r ON r.id = i.resident_id WHERE i.condominium_id = :c ORDER BY i.created_at DESC, i.id DESC LIMIT 2000");
        $stmt->execute(['c' => $condominiumId]);
        $now = date('Y-m-d H:i:s');
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'resident_id' => (int)$r['resident_id'], 'name' => (string)$r['name'],
            'email' => RegistryReports::maskEmail($r['email']), 'status' => $r['status'] === 'pending' && (string)$r['expires_at'] <= $now ? 'expired' : (string)$r['status'],
            'expires_at' => (string)$r['expires_at'], 'accepted_at' => $r['accepted_at'], 'created_at' => (string)$r['created_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
