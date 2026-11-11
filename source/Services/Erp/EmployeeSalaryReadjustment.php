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
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Reajuste salarial em lote dos funcionários próprios do condomínio (ADR 0223): no dissídio da categoria (porteiros,
 * zeladores, faxineiros), o novo salário de cada funcionário é digitado à mão no cadastro (AP-009), um por um, com a conta
 * feita fora. O percentual é o mesmo para todos (ou para uma função).
 *
 * Reusa o que já existe:
 * - RecurringReadjustment::basisPoints e ::readjusted: o mesmo percentual (−50% a 100%, até 2 casas, diferente de zero)
 *   e o mesmo arredondamento ao centavo do reajuste de lançamentos fixos (ADR 0204) e dos contratos (ADR 0217);
 * - RegistryService::save do cadastro "employees": cada funcionário é regravado só com o salário trocado (valem as regras
 *   e a auditoria update do formulário).
 *
 * - Entram: os funcionários ativos do condomínio com salário e sem desligamento até effective_on (opcionalmente só de uma
 *   função), ou só os employee_ids informados. Regra pura problem(): funcionário não encontrado no condomínio, inativo,
 *   desligado até a data do reajuste ou sem salário cadastrado.
 * - Tudo ou nada, numa transação com os funcionários travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _employee). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro readjust por funcionário (salário antes e depois, percentual, data e
 *   motivo), só quando grava. Nada é enviado; a folha e os lançamentos fixos de salário não mudam aqui (o lançamento fixo
 *   continua no reajuste da ADR 0204).
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, função, turno e salário antes e
 * depois: nenhum nome, CPF, telefone ou e-mail. Até MAX_EMPLOYEES por vez; motivo obrigatório.
 */
final class EmployeeSalaryReadjustment
{
    public const MAX_EMPLOYEES = 300;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o funcionário pode ser reajustado. */
    public static function problem(?array $row, string $effectiveOn): ?string
    {
        $terminated = $row['terminated_on'] ?? null;
        return match (true) {
            $row === null => 'Funcionário não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Funcionário inativo.',
            $terminated !== null && $terminated !== '' && substr((string)$terminated, 0, 10) <= $effectiveOn => 'Funcionário desligado até a data do reajuste.',
            (int)($row['salary_cents'] ?? 0) <= 0 => 'Funcionário sem salário cadastrado.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function readjust(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $bp = RecurringReadjustment::basisPoints($input['percent'] ?? null);
        if ($bp === null) {
            throw new InvalidArgumentException('Percentual de −50 a 100, com até 2 casas, diferente de zero (ex.: 5,5).');
        }
        $effectiveOn = $input['effective_on'] ?? null;
        $date = is_string($effectiveOn) ? DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn) : false;
        if ($date === false || $date->format('Y-m-d') !== $effectiveOn) {
            throw new InvalidArgumentException('Data de vigência do reajuste: AAAA-MM-DD.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do reajuste (5 a 255 caracteres).');
        }
        $jobs = array_keys(RegistryService::definition('employees')['fields']['job']['options']);
        $job = $input['job'] ?? null;
        if ($job !== null && $job !== '' && !in_array($job, $jobs, true)) {
            throw new InvalidArgumentException('Função inválida.');
        }
        $job = $job === '' ? null : $job;
        $ids = null;
        if (array_key_exists('employee_ids', $input) && $input['employee_ids'] !== null) {
            if (!is_array($input['employee_ids']) || $input['employee_ids'] === []) {
                throw new InvalidArgumentException('Funcionários: informe a lista de ids ou deixe vazio para todos os ativos.');
            }
            $ids = [];
            foreach ($input['employee_ids'] as $value) {
                $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($id === false) {
                    throw new InvalidArgumentException('Funcionários: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_EMPLOYEES) {
                throw new InvalidArgumentException(sprintf('Reajuste até %d funcionários por vez.', self::MAX_EMPLOYEES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('employees')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $percent = number_format($bp / 100, 2, ',', '');
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'percent' => $percent, 'effective_on' => $effectiveOn, 'job' => $job,
            'reason' => $reason, 'before_cents' => 0, 'after_cents' => 0, 'employees' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_employees WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND (terminated_on IS NULL OR terminated_on > ?) AND salary_cents > 0" . ($job !== null ? ' AND job = ?' : '')
                    . ' ORDER BY id LIMIT ' . (self::MAX_EMPLOYEES + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $effectiveOn], $job !== null ? [$job] : []));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_EMPLOYEES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d funcionários: reajuste por função.', self::MAX_EMPLOYEES));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_employees WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $effectiveOn);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_employee' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $before = (int)$row['salary_cents'];
                $after = RecurringReadjustment::readjusted($before, $bp);
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $field === 'salary_cents' => number_format($after / 100, 2, ',', ''),
                        $value !== null && in_array($field, ['hired_on', 'terminated_on'], true) => substr((string)$value, 0, 10),
                        default => $value,
                    };
                }
                try {
                    // Também no dry_run: grava e desfaz no fim, para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'employees', array_merge($data, ['status' => $row['status']]), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_employee' => (string)$row['id']]);
                }
                $result['before_cents'] += $before;
                $result['after_cents'] += $after;
                $result['employees'][] = ['id' => (int)$row['id'], 'job' => (string)$row['job'], 'shift' => $row['shift'] !== null ? (string)$row['shift'] : null,
                    'salary_before_cents' => $before, 'salary_after_cents' => $after];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['employees'] as $e) {
                Audit::record('readjust', 'erp_employees', $e['id'], ['salary_cents' => $e['salary_before_cents']],
                    ['salary_cents' => $e['salary_after_cents'], 'percent' => $percent, 'effective_on' => $effectiveOn, 'reason' => $reason,
                        'batch' => count($result['employees'])]);
            }
        }
        $result['count'] = count($result['employees']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
