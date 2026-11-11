<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência antes da assembleia (ADR 0211), somente leitura: diz, antes de convocar e antes de abrir, o que vai dar
 * errado na assembleia (AP-018/088/017, ADR 0088), para a administradora corrigir a tempo, e não descobrir na hora da
 * votação que o item não tem como ser aprovado ou que metade das unidades não pode votar por fração.
 *
 * Reusa as regras e os dados da própria assembleia, sem cálculo paralelo:
 * - Assemblies::find (escopo e itens), Assemblies::MIN_NOTICE_DAYS (prazo do edital, o mesmo do convene);
 * - Assemblies::totalWeight (o total que a apuração usa), Assemblies::overdueCents (a mesma regra que tira o voto do
 *   inadimplente no checkIn, ADR 0039), Assemblies::weight e Assemblies::decide (o peso e a apuração);
 * - UnitBatch::measures (unidades ativas, soma das frações e frações faltando, com a tolerância params.fraction_tolerance).
 *
 * Regra pura evaluate() sobre esses fatos:
 * Erro (a assembleia sai errada assim):
 * - notice_deadline: rascunho com a primeira convocação a menos de MIN_NOTICE_DAYS dias (o convene vai recusar);
 * - no_units: o condomínio não tem unidade ativa;
 * - fraction_missing: item votado por fração e unidade ativa sem fração ideal (o voto dela é recusado);
 * - quorum_unreachable: nem com todas as unidades aptas votando sim o item é aprovado (inadimplentes de fora, quórum
 *   de dois terços ou unanimidade sobre o total do condomínio).
 * Aviso: fraction_sum (frações completas, mas a soma não dá 100%); debtors (unidades que hoje não votariam, com a
 * parte do total).
 * Informação: no_second_call (sem segunda convocação).
 *
 * Só para assembleia em rascunho, convocada ou aberta; encerrada ou cancelada = DomainException (422). Escopo pela
 * assembleia (fora = RegistryNotFound, 404). Saem contagens, percentuais e a pauta: nenhuma unidade, morador ou valor.
 */
final class AssemblyReadiness
{
    public const ERRORS = ['notice_deadline', 'no_units', 'fraction_missing', 'quorum_unreachable'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Regra pura da conferência.
     *
     * @param array{status:string, first_call_at:string, second_call_at:?string, now:string, units:int, debtors:int, missing_fraction:int,
     *   fraction_sum:float, fraction_complete:bool, items:list<array{id:int, position:int, title:string, vote_basis:string, quorum:string, total:int, eligible:int}>} $facts
     */
    public static function evaluate(array $facts): array
    {
        $checks = [];
        $add = static function (string $code, string $level, string $message, int $count = 0) use (&$checks): void {
            $checks[] = ['code' => $code, 'level' => $level, 'message' => $message, 'count' => $count];
        };
        $deadline = (new \DateTimeImmutable($facts['now']))->modify('+' . Assemblies::MIN_NOTICE_DAYS . ' days');
        if ($facts['status'] === 'draft' && new \DateTimeImmutable($facts['first_call_at']) < $deadline) {
            $add('notice_deadline', 'error', sprintf('O edital precisa sair com pelo menos %d dias de antecedência: mude a data da primeira convocação para %s ou depois.',
                Assemblies::MIN_NOTICE_DAYS, $deadline->format('d/m/Y H:i')));
        }
        if ($facts['units'] === 0) {
            $add('no_units', 'error', 'O condomínio não tem unidade ativa: ninguém pode votar.');
        }
        $byFraction = array_filter($facts['items'], static fn (array $i): bool => $i['vote_basis'] === 'fraction') !== [];
        if ($byFraction && $facts['missing_fraction'] > 0) {
            $add('fraction_missing', 'error', $facts['missing_fraction'] . ' unidade(s) ativa(s) sem fração ideal: o voto delas nos itens por fração é recusado. Preencha em Unidades.',
                $facts['missing_fraction']);
        } elseif ($byFraction && !$facts['fraction_complete'] && $facts['units'] > 0) {
            $add('fraction_sum', 'warning', 'As frações ideais somam ' . number_format($facts['fraction_sum'] * 100, 4, ',', '.') . '%, não 100%: confira em Unidades.');
        }
        $items = [];
        foreach ($facts['items'] as $item) {
            $total = max($item['total'], $item['eligible']);
            $reachable = $total > 0 && Assemblies::decide($item['eligible'], 0, 0, $total, $item['quorum'])['approved'];
            $items[] = $item + ['eligible_percent' => $total > 0 ? round($item['eligible'] * 100 / $total, 2) : 0.0, 'reachable' => $reachable];
            if (!$reachable && $facts['units'] > 0) {
                $add('quorum_unreachable', 'error', sprintf('Item %d (%s): nem com todas as unidades aptas votando sim ele é aprovado (aptas = %s%% do total, quórum %s).',
                    $item['position'], $item['title'], number_format($total > 0 ? $item['eligible'] * 100 / $total : 0, 2, ',', '.'), $item['quorum']));
            }
        }
        if ($facts['debtors'] > 0) {
            $add('debtors', 'warning', $facts['debtors'] . ' unidade(s) com conta vencida em aberto hoje: presentes, não votam (Código Civil, art. 1.335, III).', $facts['debtors']);
        }
        if ($facts['second_call_at'] === null) {
            $add('no_second_call', 'info', 'Sem segunda convocação: se não houver quórum na primeira, a assembleia não acontece.');
        }
        $count = static fn (string $level): int => count(array_filter($checks, static fn (array $c): bool => $c['level'] === $level));
        return ['ready' => $count('error') === 0, 'errors' => $count('error'), 'warnings' => $count('warning'), 'checks' => $checks, 'items' => $items];
    }

    public function check(User $user, int $assemblyId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $assemblies = new Assemblies($this->pdo, $this->scope);
        $assembly = $assemblies->find($user, $assemblyId);   // 404 fora do escopo
        if (!in_array($assembly['status'], ['draft', 'convened', 'open'], true)) {
            throw new DomainException('A assembleia já foi encerrada ou cancelada: não há o que conferir.');
        }
        $condominium = (int)$assembly['condominium_id'];
        $measures = (new UnitBatch($this->pdo, $this->scope))->measures($user, $condominium);
        $today = $now->format('Y-m-d');
        $apt = [];
        $debtors = 0;
        foreach ($measures['units'] as $unit) {
            if ($assemblies->overdueCents($condominium, (int)$unit['id'], $today) > 0) {
                $debtors++;
            } else {
                $apt[] = $unit;
            }
        }
        $items = [];
        foreach ($assembly['items'] as $item) {
            $basis = (string)$item['vote_basis'];
            $eligible = 0;
            foreach ($apt as $unit) {
                try {
                    $eligible += Assemblies::weight($basis, $unit['ideal_fraction']);
                } catch (DomainException) {
                    // sem fração ideal: o voto dela é recusado (vira o erro fraction_missing)
                }
            }
            $items[] = ['id' => (int)$item['id'], 'position' => (int)$item['position'], 'title' => (string)$item['title'], 'vote_basis' => $basis,
                'quorum' => (string)$item['quorum'], 'status' => (string)$item['status'], 'total' => $assemblies->totalWeight($condominium, $basis), 'eligible' => $eligible];
        }
        $result = self::evaluate(['status' => (string)$assembly['status'], 'first_call_at' => (string)$assembly['first_call_at'],
            'second_call_at' => $assembly['second_call_at'] === null ? null : (string)$assembly['second_call_at'], 'now' => $now->format('Y-m-d H:i:s'),
            'units' => count($measures['units']), 'debtors' => $debtors, 'missing_fraction' => (int)$measures['missing_fraction'],
            'fraction_sum' => (float)$measures['fraction_sum'], 'fraction_complete' => (bool)$measures['complete'], 'items' => $items]);
        return ['assembly_id' => $assemblyId, 'condominium_id' => $condominium, 'status' => (string)$assembly['status'],
            'first_call_at' => (string)$assembly['first_call_at'], 'as_of' => $today, 'units' => count($measures['units']), 'debtors' => $debtors] + $result;
    }
}
