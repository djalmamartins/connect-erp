<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Renovação de contratos em lote (ADR 0217): o painel operacional conta os contratos vencendo e vencidos
 * (OperationalDashboard, contracts_renewal e contracts_expired), mas renovar é contrato por contrato no formulário:
 * nova data de fim e, no aniversário, o valor mensal reajustado pelo índice do contrato. Na virada do ano são dezenas
 * (portaria, limpeza, elevador, jardinagem) com o mesmo prazo e o mesmo índice.
 *
 * Reusa o que já existe:
 * - RecurringReadjustment::basisPoints e ::readjusted: o mesmo percentual (−50% a 100%, até 2 casas) e o mesmo
 *   arredondamento do reajuste de lançamentos fixos (ADR 0204);
 * - RegistryService::save do cadastro "contracts": cada contrato é regravado só com o fim e o valor trocados (valem as
 *   regras e a auditoria do formulário: fim depois do início, valor positivo, fornecedor ativo).
 *
 * - Regras puras: renewedEnd() soma os meses ao fim atual (dia 31 vira o último dia do mês); problem() recusa contrato
 *   não encontrado no condomínio, encerrado ou cancelado, de prazo indeterminado (sem fim) ou "sem reajuste" quando
 *   veio percentual.
 * - Tudo ou nada, numa transação com os contratos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com os erros e _contract). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro renew por contrato (fim e valor antes e depois, meses, percentual e
 *   motivo), só quando grava. Nada é enviado; o lançamento fixo que paga o contrato continua no reajuste da ADR 0204.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404); até MAX_CONTRACTS; motivo obrigatório.
 */
final class ContractRenewal
{
    public const MAX_CONTRACTS = 200;
    public const MAX_MONTHS = 60;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Novo fim: soma os meses; o dia que não existe no mês de destino vira o último dia dele (regra pura). */
    public static function renewedEnd(string $endsOn, int $months): string
    {
        $end = new \DateTimeImmutable(substr($endsOn, 0, 10));
        $target = $end->modify('first day of this month')->modify("+{$months} months");
        $day = min((int)$end->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando segue para o formulário. */
    public static function problem(?array $row, ?int $basisPoints): ?string
    {
        return match (true) {
            $row === null => 'Contrato não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Contrato encerrado ou cancelado: não há vigência a renovar.',
            ($row['ends_on'] ?? null) === null || $row['ends_on'] === '' => 'Contrato de prazo indeterminado: não há fim a renovar.',
            $basisPoints !== null && ($row['readjustment'] ?? '') === 'nenhum' => 'Contrato sem reajuste: renove sem percentual.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function renew(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $months = $input['months'] ?? null;
        $months = is_int($months) || is_string($months) ? filter_var($months, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_MONTHS]]) : false;
        if ($months === false) {
            throw new InvalidArgumentException(sprintf('Meses de renovação: de 1 a %d.', self::MAX_MONTHS));
        }
        $bp = null;
        if (($input['percent'] ?? null) !== null && ($input['percent'] ?? '') !== '') {
            $bp = RecurringReadjustment::basisPoints($input['percent']);
            if ($bp === null) {
                throw new InvalidArgumentException('Percentual de −50 a 100, com até 2 casas, diferente de zero (ex.: 4,62).');
            }
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da renovação (5 a 255 caracteres).');
        }
        $raw = $input['contract_ids'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os contratos.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Contratos: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_CONTRACTS) {
            throw new InvalidArgumentException(sprintf('Renove até %d contratos por vez.', self::MAX_CONTRACTS));
        }
        $ids = array_values($ids);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('contracts')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $percent = $bp === null ? null : number_format($bp / 100, 2, ',', '');
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'months' => (int)$months, 'percent' => $percent, 'reason' => $reason,
            'before_cents' => 0, 'after_cents' => 0, 'contracts' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . ' FROM erp_contracts WHERE condominium_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row, $bp);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_contract' => (string)$id]);
                }
                $endBefore = substr((string)$row['ends_on'], 0, 10);
                $endAfter = self::renewedEnd($endBefore, (int)$months);
                $before = (int)$row['monthly_cents'];
                $after = $bp === null ? $before : RecurringReadjustment::readjusted($before, $bp);
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = match ($field) {
                        'ends_on' => $endAfter,
                        'monthly_cents' => number_format($after / 100, 2, ',', ''),
                        default => $row[$field],
                    };
                }
                try {
                    // Também no dry_run: grava e desfaz no fim, para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'contracts', array_merge($data, ['status' => $row['status']]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_contract' => (string)$id]);
                }
                $result['before_cents'] += $before;
                $result['after_cents'] += $after;
                $result['contracts'][] = ['id' => $id, 'supplier_id' => (int)$row['supplier_id'], 'readjustment' => (string)$row['readjustment'],
                    'ends_on_before' => $endBefore, 'ends_on_after' => $endAfter, 'monthly_before_cents' => $before, 'monthly_after_cents' => $after];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['contracts'] as $c) {
                Audit::record('renew', 'erp_contracts', $c['id'], ['ends_on' => $c['ends_on_before'], 'monthly_cents' => $c['monthly_before_cents']],
                    ['ends_on' => $c['ends_on_after'], 'monthly_cents' => $c['monthly_after_cents'], 'months' => (int)$months, 'percent' => $percent,
                        'reason' => $reason, 'batch' => count($ids)]);
            }
        }
        $result['count'] = count($result['contracts']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
