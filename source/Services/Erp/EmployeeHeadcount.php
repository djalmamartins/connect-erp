<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Quadro de funcionários e folha por função e turno (ADR 0224), somente leitura. O cadastro de funcionários (AP-009)
 * guarda função, turno, admissão, desligamento e salário, e o reajuste em lote (ADR 0223) muda os salários; nada resume o
 * quadro para a prestação de contas e a assembleia: quantos porteiros por turno, quanto custa a folha, quem entrou e quem
 * saiu no período.
 *
 * Regra pura summarize(), por função e turno (rótulos do próprio cadastro; sem turno = "Sem turno", sem função = "Sem
 * função"):
 * - active: ativo no fim do período (cadastro ativo, admitido até "to" e sem desligamento até "to");
 * - with_salary, salary_total_cents e salary_avg_cents: dos ativos com salário (média arredondada ao centavo; null sem
 *   salário);
 * - hires e terminations: admissões e desligamentos dentro do período (qualquer situação do cadastro).
 * Os totais do topo são a soma das linhas (as mesmas regras, inclusive "Sem turno" e "Sem função"), e a média do topo é
 * o total ÷ ativos com salário.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * grupos e as somas: nenhum nome, CPF, telefone, e-mail ou id de funcionário.
 */
final class EmployeeHeadcount
{
    public const MAX_DAYS = 731;
    public const MAX_EMPLOYEES = 5000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo por função e turno (regra pura).
     *
     * @param list<array{job:?string, shift:?string, status:string, hired_on:?string, terminated_on:?string, salary_cents:?int}> $employees
     * @return array{rows:list<array>, totals:array<string, mixed>}
     */
    public static function summarize(array $employees, string $from, string $to): array
    {
        $fields = RegistryService::definition('employees')['fields'];
        $jobs = $fields['job']['options'];
        $shifts = $fields['shift']['options'];
        $rows = [];
        foreach ($employees as $e) {
            $job = $e['job'] !== null && $e['job'] !== '' ? (string)$e['job'] : null;
            $shift = $e['shift'] !== null && $e['shift'] !== '' ? (string)$e['shift'] : null;
            $hired = $e['hired_on'] !== null ? substr((string)$e['hired_on'], 0, 10) : null;
            $ended = $e['terminated_on'] !== null && $e['terminated_on'] !== '' ? substr((string)$e['terminated_on'], 0, 10) : null;
            $active = $e['status'] === 'active' && $hired !== null && $hired <= $to && ($ended === null || $ended > $to);
            $hire = $hired !== null && $hired >= $from && $hired <= $to;
            $termination = $ended !== null && $ended >= $from && $ended <= $to;
            if (!$active && !$hire && !$termination) {
                continue;
            }
            $key = ($job ?? '') . '|' . ($shift ?? '');
            $rows[$key] ??= ['job' => $job, 'job_label' => $job !== null ? ($jobs[$job] ?? $job) : 'Sem função',
                'shift' => $shift, 'shift_label' => $shift !== null ? ($shifts[$shift] ?? $shift) : 'Sem turno',
                'active' => 0, 'with_salary' => 0, 'salary_total_cents' => 0, 'salary_avg_cents' => null, 'hires' => 0, 'terminations' => 0];
            $row = &$rows[$key];
            if ($active) {
                $row['active']++;
                if ((int)($e['salary_cents'] ?? 0) > 0) {
                    $row['with_salary']++;
                    $row['salary_total_cents'] += (int)$e['salary_cents'];
                }
            }
            $row['hires'] += $hire ? 1 : 0;
            $row['terminations'] += $termination ? 1 : 0;
            unset($row);
        }
        $totals = ['active' => 0, 'with_salary' => 0, 'salary_total_cents' => 0, 'salary_avg_cents' => null, 'hires' => 0, 'terminations' => 0];
        foreach ($rows as &$row) {
            $row['salary_avg_cents'] = $row['with_salary'] > 0 ? intdiv(2 * $row['salary_total_cents'] + $row['with_salary'], 2 * $row['with_salary']) : null;
            foreach (['active', 'with_salary', 'salary_total_cents', 'hires', 'terminations'] as $field) {
                $totals[$field] += $row[$field];
            }
        }
        unset($row);
        $totals['salary_avg_cents'] = $totals['with_salary'] > 0 ? intdiv(2 * $totals['salary_total_cents'] + $totals['with_salary'], 2 * $totals['with_salary']) : null;
        $order = array_flip(array_keys($jobs));
        $shiftOrder = array_flip(array_keys($shifts));
        usort($rows, static fn (array $a, array $b): int => [$order[$a['job'] ?? ''] ?? 999, $shiftOrder[$a['shift'] ?? ''] ?? 999]
            <=> [$order[$b['job'] ?? ''] ?? 999, $shiftOrder[$b['shift'] ?? ''] ?? 999]);
        return ['rows' => array_values($rows), 'totals' => $totals];
    }

    public function build(User $user, mixed $condominiumId, mixed $from = null, mixed $to = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $to = $to === null || $to === '' ? date('Y-m-d') : $to;
        $from = $from === null || $from === '' ? (is_string($to) ? substr($to, 0, 8) . '01' : $to) : $from;
        $start = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
        $end = is_string($to) ? DateTimeImmutable::createFromFormat('!Y-m-d', $to) : false;
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Período: AAAA-MM-DD.');
        }
        if ($from > $to || (int)$start->diff($end)->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período inválido: início até o fim, com até %d dias.', self::MAX_DAYS));
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT job, shift, status, hired_on, terminated_on, salary_cents FROM erp_employees
            WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id LIMIT ' . (self::MAX_EMPLOYEES + 1));
        $stmt->execute([(int)$id]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($employees) > self::MAX_EMPLOYEES) {
            throw new InvalidArgumentException(sprintf('Mais de %d funcionários cadastrados.', self::MAX_EMPLOYEES));
        }
        $summary = self::summarize(array_map(static fn (array $e): array => ['job' => $e['job'], 'shift' => $e['shift'], 'status' => (string)$e['status'],
            'hired_on' => $e['hired_on'], 'terminated_on' => $e['terminated_on'], 'salary_cents' => $e['salary_cents'] !== null ? (int)$e['salary_cents'] : null], $employees),
            $from, $to);
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + $summary;
    }
}
