<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Collections;
use Source\Services\Erp\CollectionScreenOptions;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Dados das telas de cobrança e multas (ADR 0080 e 0076), lidos no servidor pelo mesmo serviço da API:
 * AP-116 multas pendentes (com a origem), AP-117 atendimento (fila, histórico da unidade em
 * ?unidade=ID) e AP-039 cobrança judicial (lista, detalhe em ?caso=ID e abertura em ?abrir=UNIDADE).
 * Registrar contato, abrir caso e lançar andamento são pela API REST (erp.js, data-erp-collections).
 * A cobrança judicial só acompanha: nada vai a advogado ou tribunal e nenhum encargo é criado.
 * Sem banco ou sem as permissões de ver, collReady false = a tela segue com a demonstração.
 */
final class CollectionScreens
{
    /** Página => tela própria. */
    public const PAGES = [
        'AP-116' => 'pages/collections/pending-fines',
        'AP-117' => 'pages/collections/desk',
        'AP-039' => 'pages/collections/legal-cases',
    ];

    /** A fila e o caso usam a inadimplência: exigem também erp.agreements.view, como na API. */
    public const NEEDS_AGREEMENTS = ['AP-117', 'AP-039'];

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['collReady' => false];
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.collections.view', $user)
            || (in_array($id, self::NEEDS_AGREEMENTS, true) && !Access::can('erp.agreements.view', $user))) {
            return $data;
        }
        $context = CondominiumContext::id();
        $data += ['collContext' => $context, 'collError' => null, 'collCanManage' => Access::can('erp.collections.manage', $user)];
        try {
            $service = new Collections();
            if ($id === 'AP-116') {
                $data['finesPaid'] = filter_var($_GET['pagas'] ?? false, FILTER_VALIDATE_BOOL);
                $data['fines'] = $service->pendingFines($user, $context, $data['finesPaid']);
            } elseif ($id === 'AP-117') {
                $data += $this->deskData($user, $service, $context);
            } else {
                $data += $this->caseData($user, $service, $context, $data['collCanManage']);
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'collection_screen_failed', 'page' => $id]);
            return ['collReady' => false];
        }
        $data['collReady'] = true;
        return $data;
    }

    /** @return array<string, mixed> */
    private function deskData(User $user, Collections $service, ?int $context): array
    {
        $out = ['desk' => $service->desk($user, $context), 'deskUnit' => null, 'deskContacts' => null];
        $unit = self::positiveInt($_GET['unidade'] ?? null);
        if ($unit !== null) {
            try {
                $out['deskContacts'] = $service->contacts($user, $unit);
                $row = array_values(array_filter($out['desk']['data'], static fn (array $r): bool => $r['unit_id'] === $unit))[0] ?? null;
                $out['deskUnit'] = $row ?? ['unit_id' => $unit, 'unit_label' => null, 'amount_cents' => 0, 'max_days_late' => 0, 'entries' => 0, 'legal_case' => null,
                    'promise_broken' => false, 'action_due' => false, 'last_contact' => null, 'condominium_name' => null];
            } catch (RegistryNotFound) {
                $out['collError'] = 'Unidade não encontrada no seu escopo.';
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function caseData(User $user, Collections $service, ?int $context, bool $canManage): array
    {
        $status = is_string($_GET['situacao'] ?? null) && isset(Collections::CASE_STATUSES[$_GET['situacao']]) ? $_GET['situacao'] : null;
        $out = ['caseStatus' => $status, 'cases' => $service->listCases($user, $context, ['status' => $status]), 'caseDetail' => null,
            'caseOpenUnit' => null, 'caseOpenUnits' => [], 'caseLawyers' => []];
        $detail = self::positiveInt($_GET['caso'] ?? null);
        if ($detail !== null) {
            try {
                $out['caseDetail'] = $service->findCase($user, $detail);
            } catch (RegistryNotFound) {
                $out['collError'] = 'Caso não encontrado no seu escopo.';
            }
        }
        if ($canManage) {
            $out['caseLawyers'] = (new CollectionScreenOptions())->lawyers();
        }
        if ($canManage && $out['caseDetail'] === null && $context !== null) {
            // Unidades com conta vencida em aberto e sem caso aberto: as que podem abrir um caso.
            $open = array_flip(array_map(static fn (array $c): int => $c['unit_id'], $service->listCases($user, $context)['data']));
            $units = array_values(array_filter((new Agreements())->delinquency($user, $context)['units'],
                static fn (array $u): bool => $u['unit_id'] !== null && !isset($open[(int)$u['unit_id']])));
            $out['caseOpenUnits'] = $units;
            $pick = self::positiveInt($_GET['abrir'] ?? null);
            if ($pick !== null) {
                $out['caseOpenUnit'] = array_values(array_filter($units, static fn (array $u): bool => (int)$u['unit_id'] === $pick))[0] ?? null;
                if ($out['caseOpenUnit'] === null) {
                    $out['collError'] = isset($open[$pick]) ? 'A unidade já tem um caso judicial aberto. Acompanhe por ele.' : 'A unidade não tem conta vencida em aberto neste condomínio.';
                }
            }
        }
        return $out;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }

    /** Selo da multa pela situação (regra pura). @return array{0:string, 1:string} */
    public static function fineBadge(string $state): array
    {
        return ['open' => ['Em aberto', 'info'], 'overdue' => ['Vencida', 'danger'], 'paid' => ['Paga', 'success']][$state] ?? [$state, 'plain'];
    }

    /** Selo do caso pela situação (regra pura). @return array{0:string, 1:string} */
    public static function caseBadge(string $status): array
    {
        $tone = ['preparing' => 'info', 'filed' => 'warning', 'suspended' => 'plain', 'agreement' => 'success', 'closed' => 'plain'][$status] ?? 'plain';
        return [Collections::CASE_STATUSES[$status] ?? $status, $tone];
    }

    /** Texto do andamento (regra pura): abertura, nota, mudança de situação ou encerramento. */
    public static function eventTitle(array $event): string
    {
        $label = static fn (?string $s): string => Collections::CASE_STATUSES[$s ?? ''] ?? (string)$s;
        return match ($event['kind'] ?? '') {
            'opened' => 'Caso aberto',
            'closed' => 'Encerrado',
            'status' => $label($event['from_status'] ?? null) . ' → ' . $label($event['to_status'] ?? null),
            default => 'Nota',
        };
    }
}
