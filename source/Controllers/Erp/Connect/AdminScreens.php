<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AdminReports;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de administração, relatórios enviados, usuários e permissões do ERP (ADR 0150, sobre o ADR 0168), lidas
 * no servidor pelos mesmos serviços da API (Controllers/Api/V1/ErpAdmin):
 *  - AP-091 Administração: dados da administradora e o resumo do escopo (erp.settings.view);
 *  - AP-100 Relatórios Enviados: exportações da auditoria no período (erp.audit.view);
 *  - AP-149 Usuários do Sistema: contas com acesso aos condomínios do escopo, convite e retirada de acesso
 *    (erp.users.view; mudar, erp.users.manage);
 *  - AP-150 Permissões de Usuários: matriz papel × permissão erp.* e a exceção por usuário
 *    (erp.permissions.view; mudar, erp.permissions.manage).
 * Toda mudança vai pela API, que confere de novo permissão, nível do papel e escopo. E-mail e IP chegam
 * mascarados. Sem banco ou sem permissão, adminReady false = a tela segue com a demonstração.
 */
final class AdminScreens
{
    public const PAGES = ['AP-091' => 'admin', 'AP-100' => 'sent', 'AP-149' => 'users', 'AP-150' => 'permissions'];
    public const VIEW = ['AP-091' => 'erp.settings.view', 'AP-100' => 'erp.audit.view', 'AP-149' => 'erp.users.view', 'AP-150' => 'erp.permissions.view'];
    public const MANAGE = ['AP-149' => 'erp.users.manage', 'AP-150' => 'erp.permissions.manage'];
    public const EFFECTS = ['inherit' => 'Segue o papel', 'allow' => 'Liberar', 'deny' => 'Bloquear'];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return self::VIEW;
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $kind = self::PAGES[$id] ?? null;
        if ($kind === null || Connect::databaseDisabled() || !Access::can(self::VIEW[$id], $user)) {
            return ['adminReady' => false];
        }
        $manage = self::MANAGE[$id] ?? null;
        $data = ['adminReady' => false, 'adminKind' => $kind, 'adminError' => null, 'canManageAdmin' => $manage !== null && Access::can($manage, $user)];
        try {
            $pdo = Connect::getInstance();
            if ($kind === 'admin') {
                $data['overview'] = (new AdminReports($pdo))->overview($user);
            } elseif ($kind === 'sent') {
                // Sem filtro na URL, abre no ano corrente até hoje (como Logs); antes a tela abria só com o aviso de datas inválidas.
                $sentFrom = isset($_GET['de']) && $_GET['de'] !== '' ? $_GET['de'] : date('Y-01-01');
                $sentTo = isset($_GET['ate']) && $_GET['ate'] !== '' ? $_GET['ate'] : date('Y-m-d');
                $data['sent'] = (new AdminReports($pdo))->sent($user, $sentFrom, $sentTo, self::report($_GET['relatorio'] ?? null));
                $data['scopeCondominiums'] = self::scopeCondominiums($pdo, $user);
            } elseif ($kind === 'users') {
                $context = CondominiumContext::id();
                try {
                    $data['erpUsers'] = (new ErpAccess($pdo))->users($user, $context);
                } catch (CondominiumAccessDenied) {
                    $data['erpUsers'] = ['condominium_id' => $context, 'truncated' => false, 'rows' => []];
                    $data['adminError'] = 'Condomínio não encontrado no seu escopo.';
                }
                $data['scopeCondominiums'] = self::scopeCondominiums($pdo, $user);
            } else {
                $access = new ErpAccess($pdo);
                $data['matrix'] = $access->matrix($user);
                $data['erpUsers'] = $access->users($user, null);
                $data['selectedUser'] = self::selected($_GET['usuario'] ?? null, $data['erpUsers']['rows']);
                $data['userPermissions'] = null;
                if ($data['selectedUser'] !== null) {
                    try {
                        $data['userPermissions'] = $access->userPermissions($user, $data['selectedUser']);
                    } catch (CondominiumAccessDenied | RegistryForbidden) {
                        $data['selectedUser'] = null;
                        $data['adminError'] = 'Usuário não encontrado no seu escopo.';
                    }
                }
            }
        } catch (InvalidArgumentException $error) {
            $data['adminError'] = $error->getMessage();
            $data['sent'] = null;
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'admin_screen_failed', 'page' => $id]);
            return ['adminReady' => false];
        }
        $data['adminReady'] = true;
        return $data;
    }

    /** Filtro de relatório da tela (regra pura): o mesmo formato que a API aceita; senão, todos. */
    public static function report(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9_-]{1,60}$/', $value) ? $value : null;
    }

    /**
     * Usuário escolhido na tela de permissões (regra pura): só um id que está na lista do escopo; o resto vira
     * null, para a tela não consultar quem o administrador não enxerga.
     *
     * @param list<array{id:int}> $rows
     */
    public static function selected(mixed $value, array $rows): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id !== false && in_array((int)$id, array_map('intval', array_column($rows, 'id')), true) ? (int)$id : null;
    }

    /** Efeito atual da exceção por usuário (regra pura): sem exceção = inherit. */
    public static function effect(?string $override): string
    {
        return $override !== null && isset(self::EFFECTS[$override]) ? $override : 'inherit';
    }

    /** Tamanho legível (regra pura) para a coluna do relatório enviado; sem tamanho, travessão. */
    public static function size(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        return $bytes < 1048576 ? number_format($bytes / 1024, 1, ',', '.') . ' KB' : number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    /** @return array<int, string> condomínios do escopo de quem consulta (id => nome) */
    private static function scopeCondominiums(PDO $pdo, User $user): array
    {
        $ids = array_values(array_filter(array_map('intval', (new CondominiumScope($pdo))->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $pdo->query('SELECT id, name FROM condominiums WHERE id IN (' . implode(',', $ids) . ') ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        return array_column(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows), 'name', 'id');
    }
}
