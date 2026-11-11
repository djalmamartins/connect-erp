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
 * Reajuste de lançamentos fixos (ADR 0204): aplica um percentual (IPCA, IGP-M, dissídio, índice do contrato) ao valor de
 * vários lançamentos fixos de um condomínio de uma vez (portaria, limpeza, elevador, administração), a partir de uma
 * competência, com motivo. É a rotina de aniversário de contrato e de virada de ano.
 *
 * - Valor novo (regra pura readjusted): centavos × (1 + percentual), arredondado ao centavo (meio para cima), em
 *   aritmética inteira. Percentual de −50% a 100%, até 2 casas, diferente de zero (regra pura basisPoints).
 * - Recusa (regra pura problem): modelo não encontrado no condomínio (ou excluído); inativo; encerrado antes da
 *   competência do reajuste. As regras do formulário valem, porque cada modelo é regravado pelo RegistryService::save do
 *   cadastro "recurring_entries" só com o valor trocado (conta, fornecedor e conta bancária ativos, natureza da conta).
 * - Lançamentos já gerados não mudam: os em aberto da competência do reajuste em diante vêm listados por modelo
 *   (already_generated, quantidade e valor) e no aviso already_generated, para conferir um a um. Os próximos meses já
 *   nascem com o valor novo (RecurringEntries::generate lê o modelo).
 * - Tudo ou nada, numa transação com os modelos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com os erros e _recurring). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: a update do formulário e um registro readjust por modelo (valor antes e depois, percentual, competência e
 *   motivo), só quando grava.
 *
 * Um condomínio só, do escopo (fora = CondominiumAccessDenied, 404); até MAX_TEMPLATES modelos; motivo obrigatório.
 */
final class RecurringReadjustment
{
    public const MAX_TEMPLATES = 200;
    public const MIN_BASIS_POINTS = -5000;
    public const MAX_BASIS_POINTS = 10000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Percentual em centésimos de ponto ("4,5" = 450; "-10" = −1000); null se inválido (regra pura). */
    public static function basisPoints(mixed $percent): ?int
    {
        if (is_int($percent)) {
            $percent = (string)$percent;
        } elseif (is_float($percent)) {
            $percent = number_format($percent, 2, '.', '');
        }
        if (!is_string($percent) || preg_match('/^(-?)(\d{1,3})(?:[.,](\d{1,2}))?$/', trim($percent), $m) !== 1) {
            return null;
        }
        $bp = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        $bp = $m[1] === '-' ? -$bp : $bp;
        return $bp === 0 || $bp < self::MIN_BASIS_POINTS || $bp > self::MAX_BASIS_POINTS ? null : $bp;
    }

    /** Valor reajustado em centavos, arredondado meio para cima, em aritmética inteira (regra pura). */
    public static function readjusted(int $cents, int $basisPoints): int
    {
        return intdiv(2 * $cents * (10000 + $basisPoints) + 10000, 20000);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando segue para o formulário. */
    public static function problem(?array $row, string $from): ?string
    {
        return match (true) {
            $row === null => 'Lançamento fixo não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'Lançamento fixo inativo: reative antes de reajustar.',
            ($row['ends_competence'] ?? null) !== null && (string)$row['ends_competence'] < $from
                => sprintf('Lançamento fixo encerrado em %s, antes da competência do reajuste.', (string)$row['ends_competence']),
            default => null,
        };
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha os lançamentos fixos.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Lançamentos fixos: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_TEMPLATES) {
            throw new InvalidArgumentException(sprintf('Reajuste até %d lançamentos fixos por vez.', self::MAX_TEMPLATES));
        }
        return array_values($ids);
    }

    /** @param array<string, mixed> $input */
    public function readjust(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $bp = self::basisPoints($input['percent'] ?? null);
        if ($bp === null) {
            throw new InvalidArgumentException('Percentual de −50 a 100, com até 2 casas, diferente de zero (ex.: 4,62).');
        }
        $from = $input['from_competence'] ?? null;
        if (!is_string($from) || !RecurringEntries::isCompetence($from)) {
            throw new InvalidArgumentException('Informe a competência do reajuste (AAAA-MM).');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo do reajuste (5 a 255 caracteres).');
        }
        $ids = self::ids($input['recurring_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('recurring_entries')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $percent = number_format($bp / 100, 2, ',', '');
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'percent' => $percent, 'from_competence' => $from, 'reason' => $reason,
            'count' => count($ids), 'before_cents' => 0, 'after_cents' => 0, 'templates' => [], 'warnings' => []];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_recurring_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $stmt = $this->pdo->prepare("SELECT recurring_entry_id, COUNT(*) AS n, SUM(amount_cents) AS cents FROM erp_entries
                WHERE condominium_id = ? AND recurring_entry_id IN ({$marks}) AND competence >= ? AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL
                GROUP BY recurring_entry_id");
            $stmt->execute(array_merge([(int)$condominium], $ids, [$from]));
            $generated = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $generated[(int)$r['recurring_entry_id']] = ['count' => (int)$r['n'], 'cents' => (int)$r['cents']];
            }
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row, $from);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_recurring' => (string)$id]);
                }
                $before = (int)$row['amount_cents'];
                $after = self::readjusted($before, $bp);
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $field === 'amount_cents' ? number_format($after / 100, 2, ',', '') : $row[$field];
                }
                try {
                    // Também no dry_run: grava e desfaz no fim (rollback), para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'recurring_entries', array_merge($data, ['status' => $row['status']]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_recurring' => (string)$id]);
                }
                $result['before_cents'] += $before;
                $result['after_cents'] += $after;
                $result['templates'][] = ['id' => $id, 'description' => (string)$row['description'], 'nature' => (string)$row['nature'],
                    'before_cents' => $before, 'after_cents' => $after, 'already_generated' => $generated[$id] ?? ['count' => 0, 'cents' => 0]];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $pending = array_sum(array_map(static fn (array $t): int => $t['already_generated']['count'], $result['templates']));
        if ($pending > 0) {
            $result['warnings'][] = ['code' => 'already_generated', 'message' => sprintf(
                '%d lançamento(s) em aberto já gerado(s) a partir de %s continua(m) com o valor antigo: confira um a um.', $pending, $from)];
        }
        if (!$dryRun) {
            foreach ($result['templates'] as $template) {
                Audit::record('readjust', 'erp_recurring_entries', $template['id'], ['amount_cents' => $template['before_cents']],
                    ['amount_cents' => $template['after_cents'], 'percent' => $percent, 'from_competence' => $from, 'reason' => $reason, 'batch' => count($ids)]);
            }
        }
        $result['changed'] = $dryRun ? 0 : count($result['templates']);
        return $result;
    }
}
