<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Relógios parados inativados em lote (ADR 0299). A conferência das leituras do mês (ADR 0220) aponta como missing todo
 * relógio ativo sem leitura na competência: o relógio retirado (unidade que passou a ser medida pela concessionária, troca
 * de medidor com número novo) segue ativo no cadastro, aparece todo mês como leitura em falta e na escolha do relógio ao
 * lançar a leitura. Inativar era um por um, pelo cadastro.
 *
 * Reusa o que já existe: o cadastro do registro (RegistryService::definition('meters'): a tabela, a permissão e os tipos),
 * as leituras (erp_meter_readings, uma por relógio e competência; cobrada quando tem entry_id, como em MeterReadings::bill)
 * e o período por competência (DiscountOverview::months(), no mês do banco). Inativar é o status inactive do cadastro (com
 * updated_by), o mesmo que a leitura (MeterReadings::meter) e a conferência já respeitam; nada é apagado, e as leituras e
 * as cobranças já feitas continuam apontando o relógio.
 *
 * - Entram: sem meter_ids, os relógios ativos do condomínio sem leitura nos últimos months meses (padrão DEFAULT_MONTHS,
 *   de MIN_MONTHS a MAX_MONTHS) e cadastrados e instalados antes do período; os parados com leitura ainda não cobrada (valor
 *   acima de zero e sem entry_id) ficam de fora e são contados em skipped_unbilled. Com meter_ids, só os informados. Regra
 *   pura problem(): não encontrado no condomínio, já inativo, com leitura não cobrada (a cobrança não confere a situação do
 *   relógio), com leitura no período, ou cadastrado ou instalado no período.
 * - Tudo ou nada, numa transação com os relógios travados (FOR UPDATE): a leitura trava o relógio antes de gravar
 *   (MeterReadings::record), e a chave estrangeira da leitura confere o relógio. Um recusado desfaz todos
 *   (RegistryValidationException com _form e _meter). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por relógio (status, meses, última competência, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo e a última competência:
 * nenhum número de relógio, unidade, leitura, consumo nem valor. Até MAX_METERS por vez; motivo obrigatório.
 */
final class MeterDeactivation
{
    public const MAX_METERS = 500;
    public const DEFAULT_MONTHS = 12;
    public const MIN_MONTHS = 3;
    public const MAX_MONTHS = 120;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, string> os tipos de relógio do cadastro */
    public static function kinds(): array
    {
        return (array)RegistryService::definition('meters')['fields']['kind']['options'];
    }

    /**
     * Motivo da recusa (regra pura); null quando o relógio pode ser inativado.
     *
     * @param array{status:string, since:string}|null $row since = a data mais recente entre o cadastro e a instalação
     */
    public static function problem(?array $row, ?string $lastCompetence, int $unbilled, string $fromMonth, int $months): ?string
    {
        return match (true) {
            $row === null => 'Relógio não encontrado neste condomínio.',
            $row['status'] !== 'active' => 'O relógio já está inativo.',
            $unbilled > 0 => 'Há leitura deste relógio ainda não cobrada: cobre a competência antes de inativar.',
            $lastCompetence !== null && $lastCompetence >= $fromMonth => sprintf('O relógio teve leitura nos últimos %d meses.', $months),
            $row['since'] >= $fromMonth . '-01' => sprintf('O relógio foi cadastrado ou instalado nos últimos %d meses.', $months),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $months = self::DEFAULT_MONTHS;
        if (array_key_exists('months', $input) && $input['months'] !== null && $input['months'] !== '') {
            $months = $int($input['months']);
            if ($months === false || $months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Meses sem leitura: de %d a %d.', self::MIN_MONTHS, self::MAX_MONTHS));
            }
        }
        $ids = null;
        if (array_key_exists('meter_ids', $input) && $input['meter_ids'] !== null) {
            if (!is_array($input['meter_ids']) || $input['meter_ids'] === []) {
                throw new InvalidArgumentException('Relógios: informe a lista de ids ou deixe vazio para todos os parados.');
            }
            $ids = [];
            foreach ($input['meter_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Relógios: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_METERS) {
                throw new InvalidArgumentException(sprintf('Até %d relógios por vez.', self::MAX_METERS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O mês do banco, para comparar com as competências gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $period = DiscountOverview::months($today, (int)$months);
        $fromMonth = $period[0];
        $meters = (string)RegistryService::definition('meters')['table'];
        $kinds = self::kinds();

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'months' => (int)$months, 'from' => $fromMonth,
            'to' => $period[count($period) - 1], 'meters' => [], 'skipped_unbilled' => 0];
        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT id, kind, status, created_at, installed_on FROM {$meters} WHERE condominium_id = ? AND deleted_at IS NULL";
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'active'";
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $created = substr((string)$r['created_at'], 0, 10);
                $installed = $r['installed_on'] === null ? '' : substr((string)$r['installed_on'], 0, 10);
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'], 'since' => max($created, $installed)];
            }
            $last = [];
            $unbilled = [];
            if ($byId !== []) {
                $in = implode(',', array_fill(0, count($byId), '?'));
                $stmt = $this->pdo->prepare("SELECT meter_id, MAX(competence) AS last_competence,
                        SUM(CASE WHEN entry_id IS NULL AND amount_cents > 0 THEN 1 ELSE 0 END) AS unbilled
                    FROM erp_meter_readings WHERE condominium_id = ? AND meter_id IN ({$in}) GROUP BY meter_id");
                $stmt->execute(array_merge([(int)$condominium], array_keys($byId)));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $last[(int)$r['meter_id']] = (string)$r['last_competence'];
                    $unbilled[(int)$r['meter_id']] = (int)$r['unbilled'];
                }
            }
            $check = fn (?array $row, int $id): ?string => self::problem($row, $last[$id] ?? null, $unbilled[$id] ?? 0, $fromMonth, (int)$months);
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $row) {
                    if ($check($row, $id) === null) {
                        $rows[] = $row;
                    } elseif (($unbilled[$id] ?? 0) > 0 && !(isset($last[$id]) && $last[$id] >= $fromMonth) && $row['since'] < $fromMonth . '-01') {
                        $result['skipped_unbilled']++;   // parado, mas com leitura não cobrada
                    }
                }
                if (count($rows) > self::MAX_METERS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d relógios parados: inative em partes (escolha os ids).', self::MAX_METERS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = $check($byId[$id] ?? null, $id);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_meter' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE {$meters} SET status = 'inactive', updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'active'
                AND deleted_at IS NULL");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Um relógio mudou de situação enquanto a inativação era pedida.');
                }
                $result['meters'][] = ['id' => $row['id'], 'kind' => $row['kind'], 'last_competence' => $last[$row['id']] ?? null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['meters'] as $m) {
                Audit::record('update', $meters, $m['id'], ['status' => 'active'], ['status' => 'inactive', 'months' => (int)$months,
                    'last_competence' => $m['last_competence'], 'reason' => $reason, 'batch' => count($result['meters'])]);
            }
        }
        $byKind = array_fill_keys(array_keys($kinds), 0);
        foreach ($result['meters'] as $m) {
            if (isset($byKind[$m['kind']])) {
                $byKind[$m['kind']]++;
            }
        }
        $result['by_kind'] = array_map(static fn (string $kind, int $n): array => ['kind' => $kind, 'kind_label' => (string)$kinds[$kind], 'meters' => $n],
            array_keys($byKind), array_values($byKind));
        $result['count'] = count($result['meters']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
