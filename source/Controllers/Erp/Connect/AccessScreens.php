<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\RegistryReports;
use Source\Services\Erp\ResidentInvitations;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de acesso de imobiliárias e moradores (ADR 0147, sobre o ADR 0090), lidas no servidor pelos mesmos
 * serviços da API (Controllers/Api/V1/AccessAdmin):
 *  - AP-148 Usuários de imobiliárias: imobiliárias com os usuários e os condomínios concedidos; cadastrar,
 *    desativar, vincular usuário comum, conceder e revogar condomínio, desvincular;
 *  - AP-152 Criação de usuários para moradores: convites do condomínio e o convite em lote (exige também
 *    erp.residents.view, como na API);
 *  - AP-153 Permissões de Moradores: a matriz do portal do morador do condomínio, tudo desligado por padrão.
 * Ver exige erp.access_admin.view; mudar, erp.access_admin.manage. E-mail, telefone e documento chegam
 * mascarados. Sem banco ou sem permissão, accessReady false = a tela segue com a demonstração.
 */
final class AccessScreens
{
    public const PAGES = ['AP-148' => 'partners', 'AP-152' => 'invitations', 'AP-153' => 'portal'];
    public const EXTRA_PERMISSION = ['AP-152' => 'erp.residents.view'];
    public const INVITATION_STATUS = ['pending' => ['Aguardando aceite', 'warning'], 'accepted' => ['Aceito', 'success'], 'revoked' => ['Revogado', 'plain'],
        'expired' => ['Vencido', 'danger']];
    public const RELATIONSHIPS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.access_admin.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $kind = self::PAGES[$id] ?? null;
        $extra = self::EXTRA_PERMISSION[$id] ?? null;
        if ($kind === null || Connect::databaseDisabled() || !Access::can('erp.access_admin.view', $user) || ($extra !== null && !Access::can($extra, $user))) {
            return ['accessReady' => false];
        }
        $context = CondominiumContext::id();
        $data = ['accessReady' => false, 'accessKind' => $kind, 'accessContext' => $context, 'accessError' => null,
            'accessNeedsCondo' => $kind !== 'partners' && $context === null, 'canManageAccess' => Access::can('erp.access_admin.manage', $user)];
        try {
            $pdo = Connect::getInstance();
            if ($kind === 'partners') {
                $data['agencies'] = (new PartnerAccess())->listAgencies($user);
                $data['scopeCondominiums'] = self::scopeCondominiums($pdo, $user);
                $data['eligibleUsers'] = $data['canManageAccess'] ? self::eligibleUsers($pdo, (int)$user->id) : [];
            } elseif ($context !== null) {
                try {
                    if ($kind === 'invitations') {
                        $data['invitations'] = (new ResidentInvitations())->list($user, $context);
                        $data['invitableResidents'] = self::residents($pdo, $context);
                    } else {
                        $data['portalMatrix'] = (new ResidentPortalPermissions())->matrix($user, $context);
                    }
                } catch (CondominiumAccessDenied | RegistryNotFound) {
                    $data['accessError'] = 'Condomínio não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'access_screen_failed', 'page' => $id]);
            return ['accessReady' => false];
        }
        $data['accessReady'] = true;
        return $data;
    }

    /** Nome dos condomínios de uma lista de ids (regra pura); id fora do mapa some. @param array<int, string> $names */
    public static function condominiumNames(array $ids, array $names): array
    {
        return array_values(array_filter(array_map(static fn (int $id): ?string => $names[$id] ?? null, $ids), static fn (?string $n): bool => $n !== null));
    }

    /** @return array<int, string> condomínios do escopo de quem concede (id => nome) */
    private static function scopeCondominiums(PDO $pdo, User $user): array
    {
        $ids = array_values(array_filter(array_map('intval', (new CondominiumScope($pdo))->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $pdo->query('SELECT id, name FROM condominiums WHERE id IN (' . implode(',', $ids) . ') ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        return array_column(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows), 'name', 'id');
    }

    /**
     * Usuários que podem virar parceiro (as mesmas regras de PartnerAccess::linkUser): nível comum, sem papel,
     * sem acesso a condomínio, sem permissão própria e sem vínculo; nunca quem está concedendo.
     *
     * @return list<array{id:int, label:string}>
     */
    private static function eligibleUsers(PDO $pdo, int $self): array
    {
        $stmt = $pdo->prepare('SELECT u.id, u.first_name, u.last_name, u.email FROM users u WHERE u.level <= :level AND u.id <> :self
            AND NOT EXISTS (SELECT 1 FROM access_user_roles r WHERE r.user_id = u.id) AND NOT EXISTS (SELECT 1 FROM user_condominium_access a WHERE a.user_id = u.id)
            AND NOT EXISTS (SELECT 1 FROM access_user_overrides o WHERE o.user_id = u.id) AND NOT EXISTS (SELECT 1 FROM erp_partner_agency_users p WHERE p.user_id = u.id)
            ORDER BY u.first_name, u.last_name, u.id LIMIT 500');
        $stmt->execute(['level' => PartnerAccess::MAX_USER_LEVEL, 'self' => $self]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => trim($r['first_name'] . ' ' . $r['last_name']) . ' · ' . RegistryReports::maskEmail($r['email'])],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int, name:string, unit_label:string, relationship:string, email:string, has_email:bool}> moradores ativos do condomínio */
    private static function residents(PDO $pdo, int $condominium): array
    {
        $stmt = $pdo->prepare("SELECT id, name, unit_label, relationship, email FROM erp_residents WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'
            ORDER BY unit_label, name LIMIT " . ResidentInvitations::MAX_BATCH);
        $stmt->execute(['c' => $condominium]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'unit_label' => (string)$r['unit_label'],
            'relationship' => (string)$r['relationship'], 'email' => RegistryReports::maskEmail($r['email']), 'has_email' => filter_var($r['email'], FILTER_VALIDATE_EMAIL) !== false],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
