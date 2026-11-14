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
 * Unidades vagas e o que ficou nelas (ADR 0270), somente leitura. O perfil de ocupação (ADR 0240) conta as unidades pelos
 * vínculos; os lotes de visitantes, veículos e animais (ADR 0265, 0267 e 0269) acertam um cadastro por vez; a saída da
 * unidade (ADR 0206) avisa da dívida de uma unidade por vez. Ninguém vê, de uma vez, quantas unidades ativas estão sem
 * morador ativo, há quanto tempo, quantas ainda têm visitante liberado, veículo ou animal ativo, e quantas carregam conta
 * vencida em aberto (a dívida é da unidade e continua). A administradora usa isso antes de rodar os lotes e para a
 * cobrança das unidades vazias.
 *
 * Reusa o que já existe: a regra de morador ativo das listas do cadastro e dos lotes (vínculo ativo, sem excluído e sem
 * fim de vínculo passado, como no ADR 0265), as situações dos cadastros (só os ativos, sem os excluídos) e a regra da
 * dívida vencida da saída da unidade (ADR 0206: receita ativa, sem pagamento e com vencimento antes de hoje).
 *
 * Regra pura ageBucket() e summarize(), por unidade ativa sem morador ativo (vaga):
 * - vacancy: há quanto tempo, pelo fim de vínculo mais recente da unidade: never_linked (nenhum vínculo no cadastro),
 *   unknown_date (vínculos sem fim informado), ate_30, de_31_a_180 e mais_de_180 dias; soma units.vacant;
 * - rows: uma linha por cadastro (visitors, vehicles, pets), sempre as três, com units (vagas com pelo menos um registro
 *   ativo) e records; totals.records é a soma das linhas;
 * - units: active (unidades ativas), occupied, vacant (occupied + vacant = active), with_leftovers (vagas com algum
 *   registro, sem contar duas vezes) e clean (vacant - with_leftovers);
 * - debt: vagas com conta vencida em aberto (units), as contas (entries) e o valor (amount_cents).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * grupos e as contagens: nenhum nome, documento, placa, rótulo ou id de unidade, ou observação.
 */
final class VacantUnits
{
    public const REGISTRIES = ['visitors' => ['erp_visitors', 'Visitantes liberados'], 'vehicles' => ['erp_vehicles', 'Veículos ativos'],
        'pets' => ['erp_pets', 'Animais ativos']];
    public const VACANCY = ['never_linked' => 'Nunca teve vínculo', 'unknown_date' => 'Sem data de saída', 'ate_30' => 'Até 30 dias',
        'de_31_a_180' => 'De 31 a 180 dias', 'mais_de_180' => 'Mais de 180 dias'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa da vaga pelo fim de vínculo mais recente (regra pura). */
    public static function ageBucket(bool $everLinked, ?string $lastEnd, string $on): string
    {
        if (!$everLinked) {
            return 'never_linked';
        }
        $end = substr((string)$lastEnd, 0, 10);
        if ($end === '') {
            return 'unknown_date';
        }
        $days = (new DateTimeImmutable($end)) <= new DateTimeImmutable($on) ? (int)(new DateTimeImmutable($end))->diff(new DateTimeImmutable($on))->days : 0;
        return $days <= 30 ? 'ate_30' : ($days <= 180 ? 'de_31_a_180' : 'mais_de_180');
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<int> $activeUnits
     * @param array<int, true> $occupied unidades com morador ativo
     * @param array<int, array{ever:bool, last_end:?string}> $history vínculos das unidades
     * @param array<string, array<int, int>> $records cadastro => unidade => registros ativos
     * @param array<int, array{entries:int, amount_cents:int}> $debts unidade => contas vencidas em aberto
     */
    public static function summarize(array $activeUnits, array $occupied, array $history, array $records, array $debts, string $on): array
    {
        $vacant = array_values(array_filter($activeUnits, static fn (int $u): bool => !isset($occupied[$u])));
        $vacancy = array_fill_keys(array_keys(self::VACANCY), 0);
        foreach ($vacant as $unit) {
            $vacancy[self::ageBucket($history[$unit]['ever'] ?? false, $history[$unit]['last_end'] ?? null, $on)]++;
        }
        $rows = [];
        $leftovers = [];
        foreach (self::REGISTRIES as $registry => [, $label]) {
            $units = 0;
            $count = 0;
            foreach ($vacant as $unit) {
                $n = (int)($records[$registry][$unit] ?? 0);
                if ($n > 0) {
                    $units++;
                    $count += $n;
                    $leftovers[$unit] = true;
                }
            }
            $rows[] = ['registry' => $registry, 'registry_label' => $label, 'units' => $units, 'records' => $count];
        }
        $debt = ['units' => 0, 'entries' => 0, 'amount_cents' => 0];
        foreach ($vacant as $unit) {
            if (($debts[$unit]['entries'] ?? 0) > 0) {
                $debt['units']++;
                $debt['entries'] += (int)$debts[$unit]['entries'];
                $debt['amount_cents'] += (int)$debts[$unit]['amount_cents'];
            }
        }
        $vacancyRows = [];
        foreach (self::VACANCY as $bucket => $label) {
            $vacancyRows[] = ['bucket' => $bucket, 'bucket_label' => $label, 'units' => $vacancy[$bucket]];
        }
        return ['units' => ['active' => count($activeUnits), 'occupied' => count($activeUnits) - count($vacant), 'vacant' => count($vacant),
            'with_leftovers' => count($leftovers), 'clean' => count($vacant) - count($leftovers)],
            'vacancy' => $vacancyRows, 'rows' => $rows, 'totals' => ['records' => array_sum(array_column($rows, 'records'))], 'debt' => $debt];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar
        $c = (int)$id;
        $today = date('Y-m-d');

        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute([$c]);
        $activeUnits = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $this->pdo->prepare("SELECT DISTINCT unit_id FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND (ends_on IS NULL OR ends_on >= ?) AND unit_id IS NOT NULL");
        $stmt->execute([$c, $today]);
        $occupied = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $stmt = $this->pdo->prepare('SELECT unit_id, MAX(ends_on) AS last_end FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL
            AND unit_id IS NOT NULL GROUP BY unit_id');
        $stmt->execute([$c]);
        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history[(int)$row['unit_id']] = ['ever' => true, 'last_end' => $row['last_end'] === null ? null : (string)$row['last_end']];
        }

        $records = [];
        foreach (self::REGISTRIES as $registry => [$table]) {
            $stmt = $this->pdo->prepare("SELECT unit_id, COUNT(*) AS n FROM {$table} WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                AND unit_id IS NOT NULL GROUP BY unit_id");
            $stmt->execute([$c]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $records[$registry][(int)$row['unit_id']] = (int)$row['n'];
            }
        }

        $stmt = $this->pdo->prepare("SELECT unit_id, COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS cents FROM erp_entries WHERE condominium_id = ?
            AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on < ? AND unit_id IS NOT NULL GROUP BY unit_id");
        $stmt->execute([$c, $today]);
        $debts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $debts[(int)$row['unit_id']] = ['entries' => (int)$row['n'], 'amount_cents' => (int)$row['cents']];
        }

        return ['condominium_id' => $c, 'on' => $today] + self::summarize($activeUnits, $occupied, $history, $records, $debts, $today);
    }
}
