<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Carteira de lançamentos fixos do condomínio numa competência (ADR 0272), somente leitura. O cadastro (AP-021) lista modelo
 * por modelo; a previsão de caixa (ADR 0186) usa os vigentes para projetar o saldo; a geração (ADR 0027), o reajuste
 * (ADR 0204) e o encerramento em lote (ADR 0271) agem sobre eles. Ninguém vê, de uma vez, quantos modelos estão vigentes e
 * quanto somam por mês de receita e de despesa, quantos terminam nos próximos meses (hora de renovar ou reajustar),
 * quantos já acabaram e seguem ativos, quantos começam depois, quantos vigentes ainda não geraram o lançamento da
 * competência e quantos têm conta, centro de custo, conta bancária ou fornecedor inativo (a geração vai falhar).
 *
 * Reusa o que já existe: a vigência da geração (RecurringEntries::appliesTo e isCompetence), o vínculo do lançamento gerado
 * (recurring_entry_id e competence, a mesma barreira da geração) e as situações dos cadastros (só os ativos, sem os
 * excluídos).
 *
 * Regra pura classify() e summarize(), por modelo (sem os excluídos), na competência c:
 * - inactive: situação Inativo;
 * - future: ativo, começa depois de c;
 * - ended_active: ativo, a última competência é anterior a c (o encerramento em lote pegaria);
 * - ending_soon: ativo e vigente em c, com a última competência até c + SOON_MONTHS meses;
 * - running: ativo e vigente em c, sem fim ou com o fim depois disso.
 * Uma linha por situação, sempre as cinco, com templates, receita_cents e despesa_cents (o valor mensal dos modelos);
 * totals soma as linhas. generation conta, entre os vigentes (running + ending_soon), os que já geraram o lançamento de c
 * (generated) e os que faltam (pending), somando vigentes. attention traz inactive_link (ativos com vínculo inativo) e
 * without_end (vigentes sem última competência).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações e as contagens e somas: nenhuma descrição, fornecedor, conta ou observação.
 */
final class RecurringPortfolio
{
    public const MAX_ROWS = 20000;
    public const SOON_MONTHS = 2;
    public const SITUATIONS = [
        'running' => 'Vigente',
        'ending_soon' => 'Vigente, termina em breve',
        'ended_active' => 'Já acabou e segue ativo',
        'future' => 'Começa depois',
        'inactive' => 'Inativo',
    ];
    public const LINKS = ['chart_account_id' => 'erp_chart_accounts', 'cost_center_id' => 'erp_cost_centers', 'bank_account_id' => 'erp_bank_accounts',
        'supplier_id' => 'erp_suppliers'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Competência somada de meses (regra pura). */
    public static function addMonths(string $competence, int $months): string
    {
        return (new DateTimeImmutable($competence . '-01'))->modify(sprintf('%+d months', $months))->format('Y-m');
    }

    /** Situação de um modelo na competência (regra pura). */
    public static function classify(array $row, string $competence): string
    {
        if (($row['status'] ?? '') !== 'active') {
            return 'inactive';
        }
        $starts = (string)$row['starts_competence'];
        $ends = ($row['ends_competence'] ?? null) === null || $row['ends_competence'] === '' ? null : (string)$row['ends_competence'];
        return match (true) {
            $starts > $competence => 'future',
            !RecurringEntries::appliesTo($competence, $starts, $ends) => 'ended_active',
            $ends !== null && $ends <= self::addMonths($competence, self::SOON_MONTHS) => 'ending_soon',
            default => 'running',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, status:string, nature:string, amount_cents:int, starts_competence:string, ends_competence:?string}> $templates
     * @param array<int, true> $generated modelos que já geraram a competência
     * @param array<int, true> $inactiveLink modelos com vínculo inativo
     */
    public static function summarize(array $templates, string $competence, array $generated, array $inactiveLink): array
    {
        $zero = ['templates' => 0, 'receita_cents' => 0, 'despesa_cents' => 0];
        $counts = array_fill_keys(array_keys(self::SITUATIONS), $zero);
        $generation = ['in_force' => 0, 'generated' => 0, 'pending' => 0];
        $attention = ['inactive_link' => 0, 'without_end' => 0];
        foreach ($templates as $t) {
            $situation = self::classify($t, $competence);
            $counts[$situation]['templates']++;
            $counts[$situation][$t['nature'] === 'receita' ? 'receita_cents' : 'despesa_cents'] += (int)$t['amount_cents'];
            if ($situation === 'running' || $situation === 'ending_soon') {
                $generation['in_force']++;
                $generation[isset($generated[$t['id']]) ? 'generated' : 'pending']++;
                if (($t['ends_competence'] ?? null) === null || $t['ends_competence'] === '') {
                    $attention['without_end']++;
                }
            }
            if ($situation !== 'inactive' && isset($inactiveLink[$t['id']])) {
                $attention['inactive_link']++;
            }
        }
        $rows = [];
        $totals = $zero;
        foreach (self::SITUATIONS as $situation => $label) {
            $rows[] = ['situation' => $situation, 'situation_label' => $label] + $counts[$situation];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$situation][$key];
            }
        }
        return ['soon_until' => self::addMonths($competence, self::SOON_MONTHS), 'rows' => $rows, 'totals' => $totals, 'generation' => $generation,
            'attention' => $attention];
    }

    public function build(User $user, mixed $condominiumId, mixed $competence = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $competence = $competence === null || $competence === '' ? date('Y-m') : $competence;
        if (!is_string($competence) || !RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência: AAAA-MM.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar
        $c = (int)$id;

        $stmt = $this->pdo->prepare('SELECT id, status, nature, amount_cents, starts_competence, ends_competence, chart_account_id, cost_center_id, bank_account_id,
            supplier_id FROM erp_recurring_entries WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([$c]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d lançamentos fixos: fale com o suporte.', self::MAX_ROWS));
        }

        $stmt = $this->pdo->prepare('SELECT DISTINCT recurring_entry_id FROM erp_entries WHERE condominium_id = ? AND competence = ? AND deleted_at IS NULL
            AND recurring_entry_id IS NOT NULL');
        $stmt->execute([$c, $competence]);
        $generated = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $inactiveLink = [];
        foreach (self::LINKS as $field => $table) {
            $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r[$field] ?? 0), $rows))));
            if ($ids === []) {
                continue;
            }
            $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE deleted_at IS NULL AND status = 'active' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $active = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($rows as $r) {
                $link = (int)($r[$field] ?? 0);
                if ($link > 0 && !isset($active[$link])) {
                    $inactiveLink[(int)$r['id']] = true;
                }
            }
        }

        return ['condominium_id' => $c, 'competence' => $competence] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'],
            'status' => (string)$r['status'], 'nature' => (string)$r['nature'], 'amount_cents' => (int)$r['amount_cents'],
            'starts_competence' => (string)$r['starts_competence'], 'ends_competence' => $r['ends_competence'] === null ? null : (string)$r['ends_competence']], $rows),
            $competence, $generated, $inactiveLink);
    }
}
