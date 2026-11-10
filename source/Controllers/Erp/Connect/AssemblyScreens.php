<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de assembleia (ADR 0145, sobre o ADR 0088), lidas no servidor pelo mesmo serviço da API:
 *  - AP-018 Assembleia Virtual: lista, nova assembleia com a pauta e o detalhe (?assembleia=ID) com
 *    convocar, abrir, registrar presença, encerrar e cancelar;
 *  - AP-017 Votação: a assembleia aberta, cada item com abrir e apurar a votação e o voto por unidade presente;
 *  - AP-088 Listagem de presenças e AP-092 Parecer (resultados e ata), com CSV pela API.
 * Ver exige erp.assemblies.view; mudar, erp.assemblies.manage (convocar também erp.announcements.manage), e a
 * API confere de novo. O valor vencido da unidade só aparece com erp.agreements.view (ADR 0094). Sem banco ou
 * sem permissão, assemblyReady false = a tela segue com a demonstração.
 */
final class AssemblyScreens
{
    /** Página => tipo da tela. */
    public const PAGES = ['AP-018' => 'manage', 'AP-017' => 'voting', 'AP-088' => 'attendance', 'AP-092' => 'results'];

    /** Situações que cada tela abre por padrão, na ordem de preferência. */
    public const DEFAULT_STATUS = ['voting' => ['open'], 'attendance' => ['open', 'closed'], 'results' => ['closed', 'open']];

    public const STATUS = ['draft' => ['Rascunho', 'plain'], 'convened' => ['Convocada', 'info'], 'open' => ['Em andamento', 'warning'],
        'closed' => ['Encerrada', 'success'], 'cancelled' => ['Cancelada', 'danger']];
    public const ITEM_STATUS = ['pending' => ['Aguardando', 'plain'], 'voting' => ['Em votação', 'warning'], 'decided' => ['Apurado', 'success']];
    public const KINDS = ['ordinaria' => 'Ordinária (AGO)', 'extraordinaria' => 'Extraordinária (AGE)'];
    public const MODES = ['presencial' => 'Presencial', 'virtual' => 'Virtual', 'hibrida' => 'Híbrida'];
    public const BASES = ['fraction' => 'Fração ideal', 'unit' => 'Uma unidade, um voto'];
    public const CHOICES = ['yes' => 'Sim', 'no' => 'Não', 'abstain' => 'Abstenção'];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.assemblies.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $kind = self::PAGES[$id] ?? null;
        if ($kind === null || Connect::databaseDisabled() || !Access::can('erp.assemblies.view', $user)) {
            return ['assemblyReady' => false];
        }
        $context = CondominiumContext::id();
        $canManage = Access::can('erp.assemblies.manage', $user);
        $data = ['assemblyReady' => false, 'assemblyKind' => $kind, 'assemblyContext' => $context, 'assemblyError' => null,
            'canManageAssembly' => $canManage, 'canConvene' => $canManage && Access::can('erp.announcements.manage', $user),
            'assemblyDetail' => null, 'assemblyReport' => null, 'assemblyUnits' => [], 'assemblyVoters' => [], 'assemblyDocuments' => []];
        try {
            $service = new Assemblies();
            $list = $service->list($user, $context);
            $data['assemblies'] = $list;
            $selected = self::selected($_GET['assembleia'] ?? null, $list['data'], self::DEFAULT_STATUS[$kind] ?? []);
            if ($selected !== null) {
                try {
                    $detail = $service->find($user, $selected);
                    $data['assemblyDetail'] = $detail;
                    $pdo = Connect::getInstance();
                    $condominium = (int)$detail['condominium_id'];
                    if ($kind === 'manage' && $canManage && $detail['status'] === 'open') {
                        $data['assemblyUnits'] = self::absentUnits($pdo, $condominium, $selected);
                        $data['assemblyDocuments'] = self::documents($pdo, $condominium);
                    } elseif ($kind === 'voting' && $canManage && $detail['status'] === 'open') {
                        $data['assemblyVoters'] = self::voters($pdo, $selected, self::votingItem($detail['items']));
                    } elseif ($kind === 'attendance') {
                        $data['assemblyReport'] = $service->attendanceReport($user, $selected, Access::can('erp.agreements.view', $user));
                    } elseif ($kind === 'results') {
                        $data['assemblyReport'] = $service->resultsReport($user, $selected);
                    }
                } catch (CondominiumAccessDenied | RegistryNotFound) {
                    $data['assemblyError'] = 'Assembleia não encontrada no seu escopo.';
                    $data['assemblyDetail'] = null;
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'assembly_screen_failed', 'page' => $id]);
            return ['assemblyReady' => false];
        }
        $data['assemblyReady'] = true;
        return $data;
    }

    /**
     * Assembleia aberta na tela (regra pura): a de ?assembleia=ID, senão a mais recente numa das situações
     * padrão da tela (a lista já vem da mais recente para a mais antiga), senão nenhuma.
     *
     * @param list<array{id:int, status:string}> $list
     * @param list<string> $statuses
     */
    public static function selected(mixed $value, array $list, array $statuses): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false && $id !== null) {
            return (int)$id;
        }
        foreach ($statuses as $status) {
            foreach ($list as $row) {
                if ($row['status'] === $status) {
                    return (int)$row['id'];
                }
            }
        }
        return null;
    }

    /** Ações da assembleia na situação atual (regra pura), na ordem dos botões. @return list<string> */
    public static function actions(string $status, bool $canManage, bool $canConvene): array
    {
        if (!$canManage) {
            return [];
        }
        return match ($status) {
            'draft' => array_values(array_filter([$canConvene ? 'convene' : null, 'cancel'])),
            'convened' => ['open', 'cancel'],
            'open' => ['close'],
            default => [],
        };
    }

    /** Id do item em votação, se houver (regra pura). @param list<array{id:int, status:string}> $items */
    public static function votingItem(array $items): ?int
    {
        foreach ($items as $item) {
            if ($item['status'] === 'voting') {
                return (int)$item['id'];
            }
        }
        return null;
    }

    /** Texto do resultado de um item apurado (regra pura). */
    public static function resultText(array $item): string
    {
        $result = $item['result'] ?? null;
        if (!is_array($result)) {
            return '—';
        }
        $basis = (string)$item['vote_basis'];
        return sprintf('Sim %s · Não %s · Abstenção %s de %s%s', Assemblies::share((int)$result['yes'], $basis), Assemblies::share((int)$result['no'], $basis),
            Assemblies::share((int)$result['abstain'], $basis), Assemblies::share((int)$result['total'], $basis), $basis === 'unit' ? ' unidades' : ' da fração ideal');
    }

    /** Data e hora para mostrar (regra pura). */
    public static function stamp(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $time = strtotime($value);
        return $time === false ? '—' : date('d/m/Y H:i', $time);
    }

    /** @return list<array{id:int, label:string}> unidades ativas do condomínio ainda sem presença na assembleia */
    private static function absentUnits(PDO $pdo, int $condominium, int $assembly): array
    {
        $stmt = $pdo->prepare("SELECT u.id, u.label FROM erp_units u WHERE u.condominium_id = :c AND u.deleted_at IS NULL AND u.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM erp_assembly_attendance t WHERE t.assembly_id = :a AND t.unit_id = u.id) ORDER BY u.label LIMIT " . Assemblies::MAX_ATTENDANCE);
        $stmt->execute(['c' => $condominium, 'a' => $assembly]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int, label:string}> arquivos do Disco Virtual do condomínio, para a procuração */
    private static function documents(PDO $pdo, int $condominium): array
    {
        $stmt = $pdo->prepare('SELECT id, title FROM erp_documents WHERE condominium_id = :c AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 200');
        $stmt->execute(['c' => $condominium]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['title']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Unidades presentes com direito a voto e se já votaram no item em votação.
     *
     * @return list<array{id:int, label:string, voted:bool}>
     */
    private static function voters(PDO $pdo, int $assembly, ?int $item): array
    {
        $stmt = $pdo->prepare('SELECT u.id, u.label, EXISTS (SELECT 1 FROM erp_assembly_votes v WHERE v.item_id = :i AND v.unit_id = u.id) AS voted
            FROM erp_assembly_attendance t JOIN erp_units u ON u.id = t.unit_id WHERE t.assembly_id = :a AND t.eligible = 1 ORDER BY u.label LIMIT ' . Assemblies::MAX_ATTENDANCE);
        $stmt->execute(['a' => $assembly, 'i' => $item ?? 0]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'voted' => (bool)$r['voted']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
