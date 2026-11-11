<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Inadimplência por bloco ou andar (ADR 0222), somente leitura. O relatório de inadimplentes (CollectionReports, visões
 * geral, unidade, taxa e responsável) e o painel mostram a inadimplência por condomínio ou por unidade; o conselho e a
 * administradora querem saber onde ela se concentra (uma torre, os andares altos de uma obra de fachada), sem expor quem
 * deve.
 *
 * Reusa o que já existe:
 * - Agreements::delinquency: as mesmas contas vencidas em aberto, encargos sugeridos (BillingRules e LateCharges) e dias
 *   de atraso do relatório de inadimplentes;
 * - Agreements::bucket: as mesmas faixas de atraso (1-30, 31-60, 61-90, 90+).
 *
 * Regra pura group(): agrupa as unidades em atraso pelo bloco ou pelo andar do cadastro de unidades (vazio = "Sem bloco"
 * ou "Sem andar"; conta sem unidade = "Sem unidade"), com unidades do grupo, unidades em atraso, taxa (% das unidades do
 * grupo), contas, valor, encargos, total, maior atraso e unidades por faixa (pelo maior atraso da unidade). Ordem: maior
 * total primeiro.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * grupos e as somas: nenhum nome, documento, contato, rótulo de unidade ou lançamento.
 */
final class DelinquencyByLocation
{
    public const GROUPS = ['block', 'floor'];
    public const NO_UNIT = 'Sem unidade';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Rótulo do grupo (regra pura). */
    public static function label(?string $value, string $by): string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : ($by === 'block' ? 'Sem bloco' : 'Sem andar');
    }

    /**
     * Agrupamento (regra pura).
     *
     * @param list<array{unit_id:?int, entries:list<mixed>, amount_cents:int, charges_cents:int, total_cents:int, max_days_late:int}> $units
     * @param array<int, string> $groupOf grupo de cada unidade (unit_id => rótulo)
     * @param array<string, int> $population unidades ativas por grupo
     * @return list<array>
     */
    public static function group(array $units, array $groupOf, array $population): array
    {
        $empty = ['1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $rows = [];
        foreach ($units as $unit) {
            $key = $unit['unit_id'] === null ? self::NO_UNIT : ($groupOf[$unit['unit_id']] ?? self::NO_UNIT);
            $rows[$key] ??= ['group' => $key, 'units_total' => $population[$key] ?? 0, 'units_late' => 0, 'rate_pct' => null, 'entries' => 0,
                'amount_cents' => 0, 'charges_cents' => 0, 'total_cents' => 0, 'max_days_late' => 0, 'units_by_bucket' => $empty];
            $row = &$rows[$key];
            $row['units_late']++;
            $row['entries'] += count($unit['entries']);
            $row['amount_cents'] += (int)$unit['amount_cents'];
            $row['charges_cents'] += (int)$unit['charges_cents'];
            $row['total_cents'] += (int)$unit['total_cents'];
            $row['max_days_late'] = max($row['max_days_late'], (int)$unit['max_days_late']);
            $row['units_by_bucket'][Agreements::bucket((int)$unit['max_days_late'])]++;
            unset($row);
        }
        foreach ($rows as &$row) {
            $row['rate_pct'] = $row['units_total'] > 0 ? round(100 * $row['units_late'] / $row['units_total'], 1) : null;
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => [-$a['total_cents'], $a['group']] <=> [-$b['total_cents'], $b['group']]);
        return array_values($rows);
    }

    public function build(User $user, mixed $condominiumId, mixed $by = null, mixed $asOf = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $by = $by === null || $by === '' ? 'block' : $by;
        if (!in_array($by, self::GROUPS, true)) {
            throw new InvalidArgumentException('Agrupar por: block ou floor.');
        }
        $asOf = $asOf === null || $asOf === '' ? null : $asOf;
        if ($asOf !== null) {
            $date = is_string($asOf) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) : false;
            if ($date === false || $date->format('Y-m-d') !== $asOf) {
                throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
            }
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $groupOf = [];
        $population = [];
        $stmt = $this->pdo->prepare("SELECT id, block, floor, status, deleted_at FROM erp_units WHERE condominium_id = ?");
        $stmt->execute([(int)$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $label = self::label($u[$by], $by);
            $groupOf[(int)$u['id']] = $label;
            if ($u['deleted_at'] === null && $u['status'] === 'active') {
                $population[$label] = ($population[$label] ?? 0) + 1;
            }
        }
        $base = (new Agreements($this->pdo, $this->scope))->delinquency($user, (int)$id, $asOf);
        $rows = self::group($base['units'], $groupOf, $population);
        return ['condominium_id' => (int)$id, 'as_of' => $base['as_of'], 'by' => $by, 'units_total' => array_sum($population),
            'units_late' => count(array_filter($base['units'], static fn (array $u): bool => $u['unit_id'] !== null)), 'rows' => $rows,
            'totals' => ['entries' => (int)$base['totals']['entries'], 'amount_cents' => (int)$base['totals']['amount_cents'],
                'charges_cents' => (int)($base['totals']['charges_cents'] ?? 0), 'total_cents' => (int)($base['totals']['total_cents'] ?? 0),
                'missing_index' => $base['totals']['missing_index'] ?? []]];
    }
}
