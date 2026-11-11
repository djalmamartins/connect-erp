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
 * Desligamento em lote de funcionários (ADR 0225): na troca da equipe própria por uma terceirizada (portaria, limpeza),
 * vários funcionários saem no mesmo dia; hoje cada desligamento é digitado no cadastro (AP-009), um por um. O quadro e a
 * folha (ADR 0224) e o reajuste (ADR 0223) já leem a data de desligamento.
 *
 * Reusa o que já existe: cada funcionário é regravado pelo RegistryService::save do cadastro "employees" só com a data de
 * desligamento (valem as regras do formulário, como desligamento depois da admissão, e a auditoria update). A situação
 * do cadastro não muda: o desligamento é a data, como no formulário.
 *
 * - Regra pura problem(): funcionário não encontrado no condomínio, inativo, já desligado (a data não é trocada aqui) ou
 *   admitido depois da data do desligamento.
 * - Data: até MAX_DAYS_AHEAD dias à frente (aviso prévio já combinado); ids obrigatórios (desligar é sempre escolha).
 * - Tudo ou nada, numa transação com os funcionários travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _employee). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro terminate por funcionário (data e motivo), só quando grava. Nada é
 *   enviado; rescisão, eSocial e lançamentos ficam fora daqui.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, função, turno, admissão e
 * desligamento: nenhum nome, CPF, telefone, e-mail ou salário. Até MAX_EMPLOYEES por vez; motivo obrigatório.
 */
final class EmployeeTermination
{
    public const MAX_EMPLOYEES = 300;
    public const MAX_DAYS_AHEAD = 30;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o funcionário pode ser desligado. */
    public static function problem(?array $row, string $terminatedOn): ?string
    {
        $ended = $row['terminated_on'] ?? null;
        return match (true) {
            $row === null => 'Funcionário não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Funcionário inativo.',
            $ended !== null && $ended !== '' => 'Funcionário já desligado: a data não é trocada aqui.',
            substr((string)($row['hired_on'] ?? ''), 0, 10) > $terminatedOn => 'Desligamento antes da admissão.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function terminate(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $input['terminated_on'] ?? null;
        $date = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data do desligamento: AAAA-MM-DD.');
        }
        if ($on > (new DateTimeImmutable('today'))->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d')) {
            throw new InvalidArgumentException(sprintf('Data do desligamento até %d dias à frente.', self::MAX_DAYS_AHEAD));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do desligamento (5 a 255 caracteres).');
        }
        $raw = $input['employee_ids'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os funcionários.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Funcionários: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw new InvalidArgumentException(sprintf('Desligue até %d funcionários por vez.', self::MAX_EMPLOYEES));
        }
        $ids = array_values($ids);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('employees')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'terminated_on' => $on, 'reason' => $reason, 'employees' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . ' FROM erp_employees WHERE condominium_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = $r;
            }
            foreach ($ids as $id) {
                $problem = self::problem($byId[$id] ?? null, $on);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_employee' => (string)$id]);
                }
            }
            foreach ($ids as $id) {
                $row = $byId[$id];
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $field === 'terminated_on' => $on,
                        $value === null => null,
                        $field === 'salary_cents' => number_format(((int)$value) / 100, 2, ',', ''),
                        $field === 'hired_on' => substr((string)$value, 0, 10),
                        default => $value,
                    };
                }
                try {
                    // Também no dry_run: grava e desfaz no fim, para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'employees', array_merge($data, ['status' => $row['status']]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_employee' => (string)$id]);
                }
                $result['employees'][] = ['id' => $id, 'job' => (string)$row['job'], 'shift' => $row['shift'] !== null ? (string)$row['shift'] : null,
                    'hired_on' => substr((string)$row['hired_on'], 0, 10), 'terminated_on' => $on];
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
                Audit::record('terminate', 'erp_employees', $e['id'], ['terminated_on' => null],
                    ['terminated_on' => $on, 'reason' => $reason, 'batch' => count($result['employees'])]);
            }
        }
        $result['count'] = count($result['employees']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
