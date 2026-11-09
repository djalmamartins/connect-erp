<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\ChartReports;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas dos gráficos (AP-102 a AP-109; ADR 0083 e 0078) com dados reais, lidos no servidor pelo mesmo
 * serviço da API (ChartReports). O controlador entrega os painéis já medidos (onde cada barra começa e
 * quanto mede, em %) e a tabela equivalente; a tela só desenha e formata.
 *
 * Regras de leitura (ADR 0078): um eixo por painel. Séries de unidades diferentes (a taxa em % da
 * inadimplência, ou água, gás e energia no consumo) vão para painéis separados, nunca para um segundo
 * eixo. Contas e áreas comuns, que somam um todo, ficam empilhadas; o resto, lado a lado, com a linha
 * do zero quando há valor negativo (resultado, saldo do mês, saldo por conta).
 * Sem banco ou sem permissão, chartReady false = a tela segue com a demonstração.
 */
final class ChartScreens
{
    /** Página => gráfico do serviço. */
    public const PAGES = [
        'AP-102' => 'general',
        'AP-103' => 'accounts',
        'AP-104' => 'consumption-unit',
        'AP-105' => 'consumption',
        'AP-106' => 'cash',
        'AP-107' => 'delinquency',
        'AP-108' => 'bank-accounts',
        'AP-109' => 'common-areas',
    ];

    /** Permissões por gráfico, iguais às da API (Api\V1\ChartReports). */
    public const PERMISSIONS = [
        'general' => ['erp.entries.view'],
        'accounts' => ['erp.entries.view', 'erp.chart.view'],
        'consumption-unit' => ['erp.meter_readings.view'],
        'consumption' => ['erp.meter_readings.view'],
        'cash' => ['erp.entries.view'],
        'delinquency' => ['erp.agreements.view'],
        'bank-accounts' => ['erp.entries.view', 'erp.bank_accounts.view'],
        'common-areas' => ['erp.reservations.view'],
    ];

    public const NATURES = ['despesa' => 'Despesas', 'receita' => 'Receitas'];

    /** Gráficos de composição (partes de um todo): barras empilhadas. */
    public const STACKED = ['accounts', 'common-areas'];

    /** Meses padrão na tela: os últimos 12, até o mês atual. */
    public const DEFAULT_MONTHS = 12;

    private const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** @return list<string> */
    public static function permissions(string $id): array
    {
        return self::PERMISSIONS[self::PAGES[$id]];
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['chartReady' => false];
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled()) {
            return $data;
        }
        foreach (self::permissions($id) as $permission) {
            if (!Access::can($permission, $user)) {
                return $data;
            }
        }
        $kind = self::PAGES[$id];
        $filters = self::filters($_GET, date('Y-m'));
        $data += ['chartKind' => $kind, 'chartFilters' => $filters, 'chartError' => null, 'chartUnits' => [], 'chartNeedsUnit' => false,
            'chartPanels' => [], 'chartTable' => null];
        try {
            $context = CondominiumContext::id();
            if ($kind === 'consumption-unit') {
                $data['chartUnits'] = array_values(array_filter((new RegistryService())->units($user),
                    static fn (array $u): bool => $context === null || $u['condominium_id'] === $context));
                if ($filters['unidade'] === null) {
                    $data['chartNeedsUnit'] = true;
                    $data['chartReady'] = true;
                    return $data;
                }
            }
            $service = new ChartReports();
            [$from, $to] = [$filters['de'], $filters['ate']];
            $chart = match ($kind) {
                'general' => $service->general($user, $context, $from, $to),
                'accounts' => $service->accounts($user, $context, $from, $to, $filters['natureza']),
                'consumption-unit' => $service->consumption($user, $context, $from, $to, $filters['unidade']),
                'consumption' => $service->consumption($user, $context, $from, $to),
                'cash' => $service->cash($user, $context, $from, $to),
                'delinquency' => $service->delinquency($user, $context, $from, $to),
                'bank-accounts' => $service->bankAccounts($user, $context, $from, $to),
                default => $service->commonAreas($user, $context, $from, $to),
            };
            $data['chartPanels'] = self::panels($chart, $kind);
            $data['chartTable'] = self::table($chart);
        } catch (\InvalidArgumentException $error) {
            $data['chartError'] = $error->getMessage();
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'chart_screen_failed', 'page' => $id]);
            return ['chartReady' => false];
        }
        $data['chartReady'] = true;
        return $data;
    }

    /**
     * Filtros da tela (regra pura): de e ate em AAAA-MM (padrão: os últimos 12 meses até $current),
     * natureza do AP-103 e unidade do AP-104. A faixa máxima é validada no serviço.
     *
     * @return array{de:string, ate:string, natureza:string, unidade:?int}
     */
    public static function filters(array $query, string $current): array
    {
        $to = self::month($query['ate'] ?? null) ?? $current;
        $from = self::month($query['de'] ?? null)
            ?? (new \DateTimeImmutable($to . '-01'))->modify('-' . (self::DEFAULT_MONTHS - 1) . ' months')->format('Y-m');
        $unit = filter_var($query['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return [
            'de' => $from,
            'ate' => $to,
            'natureza' => isset(self::NATURES[$query['natureza'] ?? '']) ? (string)$query['natureza'] : 'despesa',
            'unidade' => $unit === false ? null : (int)$unit,
        ];
    }

    /** Mês AAAA-MM válido ou null (regra pura). */
    public static function month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    /** Rótulo curto do mês (regra pura): 2026-11 => nov/26. */
    public static function monthLabel(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        return self::MONTHS[$number - 1] . '/' . substr((string)$year, -2);
    }

    /** Valor formatado pela unidade da série (regra pura). null = sem base (taxa sem faturamento). */
    public static function format(int|float|null $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }
        return match ($unit) {
            'cents' => ($value < 0 ? '−R$ ' : 'R$ ') . number_format(abs($value) / 100, 2, ',', '.'),
            'percent' => number_format((float)$value, 2, ',', '.') . '%',
            'consumption' => number_format((float)$value, 3, ',', '.'),
            default => number_format((float)$value, 0, ',', '.'),
        };
    }

    /**
     * Painéis do gráfico (regra pura). Um painel por unidade de medida (no consumo, um por tipo, porque
     * água, gás e energia não dividem escala). Em cada painel, cada série tem a cor da sua posição
     * (1 a 8, na ordem fixa do serviço; "Outras" fica neutra) e cada barra traz [base %, altura %, valor].
     *
     * @return list<array{title:?string, unit:string, stacked:bool, zero:float, top:int|float, bottom:int|float, empty:bool,
     *     series:list<array{key:string, label:string, color:string}>, columns:list<array{month:string, bars:list<array{0:float, 1:float, 2:int|float|null}>}>}>
     */
    public static function panels(array $chart, string $kind): array
    {
        $groups = [];
        foreach ($chart['series'] as $position => $series) {
            $unit = (string)($series['unit'] ?? $chart['unit']);
            $group = str_starts_with($kind, 'consumption') ? (string)$series['key'] : $unit;
            $groups[$group] ??= ['title' => null, 'unit' => $unit, 'series' => []];
            $groups[$group]['series'][] = $series + ['color' => $series['key'] === 'others' ? 'other' : (string)(($position % 8) + 1)];
            if (str_starts_with($kind, 'consumption') || $unit !== $chart['unit']) {
                $groups[$group]['title'] = (string)$series['label'];
            }
        }
        $panels = [];
        foreach ($groups as $group) {
            $values = array_merge(...array_map(static fn (array $s): array => array_values(array_filter($s['data'], static fn ($v): bool => $v !== null)), $group['series']));
            $stacked = in_array($kind, self::STACKED, true) && count($group['series']) > 1 && ($values === [] || min($values) >= 0);
            if ($stacked) {
                $sums = array_map(static fn (int $i): int|float => array_sum(array_map(static fn (array $s): int|float => $s['data'][$i] ?? 0, $group['series'])), array_keys($chart['labels']));
                [$top, $bottom] = [$sums === [] ? 0 : max(0, ...$sums), 0];
            } else {
                [$top, $bottom] = [$values === [] ? 0 : max(0, ...$values), $values === [] ? 0 : min(0, ...$values)];
            }
            $range = ($top - $bottom) ?: 1;
            $columns = [];
            foreach ($chart['labels'] as $i => $month) {
                $bars = [];
                $stack = 0;
                foreach ($group['series'] as $series) {
                    $value = $series['data'][$i] ?? null;
                    if ($stacked) {
                        $v = (float)($value ?? 0);
                        $bars[] = [round($stack / $range * 100, 2), round($v / $range * 100, 2), $value];
                        $stack += $v;
                        continue;
                    }
                    $v = (float)($value ?? 0);
                    $bars[] = [round((min($v, 0) - $bottom) / $range * 100, 2), round(abs($v) / $range * 100, 2), $value];
                }
                $columns[] = ['month' => (string)$month, 'bars' => $bars];
            }
            $panels[] = [
                'title' => $group['title'],
                'unit' => $group['unit'],
                'stacked' => $stacked,
                'zero' => round(-$bottom / $range * 100, 2),
                'top' => $top,
                'bottom' => $bottom,
                'empty' => array_filter($values, static fn ($v): bool => $v != 0) === [],
                'series' => array_map(static fn (array $s): array => ['key' => (string)$s['key'], 'label' => (string)$s['label'], 'color' => $s['color']], $group['series']),
                'columns' => $columns,
            ];
        }
        return $panels;
    }

    /**
     * Tabela equivalente (regra pura): uma linha por mês e uma coluna por série, com a unidade de cada
     * uma e o total do período (a taxa não soma: fica sem total).
     *
     * @return array{months:list<string>, series:list<array{label:string, unit:string, data:list<int|float|null>, total:int|float|null}>}
     */
    public static function table(array $chart): array
    {
        return [
            'months' => array_values($chart['labels']),
            'series' => array_map(static function (array $s) use ($chart): array {
                $unit = (string)($s['unit'] ?? $chart['unit']);
                return ['label' => (string)$s['label'], 'unit' => $unit, 'data' => array_values($s['data']),
                    'total' => $unit === 'percent' ? null : array_sum(array_map(static fn ($v): int|float => $v ?? 0, $s['data']))];
            }, $chart['series']),
        ];
    }
}
