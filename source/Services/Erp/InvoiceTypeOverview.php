<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Situação dos tipos de faturas do condomínio (ADR 0296), somente leitura. O cadastro de tipos de faturas (AP-168) junta
 * os tipos padrão da Connect (sem condomínio) e os do próprio condomínio; o tipo do condomínio com o mesmo nome prevalece
 * sobre o padrão (ADR 0163). Cada tipo aponta uma conta analítica de receita do plano de contas, e as faturas (AP-032) são
 * preparadas a partir dele. Ninguém vê, por recorrência, quantos tipos o condomínio tem à mão, quantos padrões ficaram
 * encobertos, quais não geraram fatura no período e quais apontam uma conta que deixou de valer.
 *
 * Reusa o que já existe: os cadastros do registro (RegistryService::definition: invoice_types para a tabela, a permissão,
 * as recorrências e a coluna de override; chart para a tabela e a permissão), a regra do override da ADR 0163 (registro
 * não excluído do condomínio com o mesmo valor encobre o padrão), a regra da conta do cadastro (analítica, de receita,
 * ativa, do padrão ou do condomínio), as faturas não canceladas (ADR 0033) e DiscountOverview::months() para o período.
 * O mês é o do banco.
 *
 * Regra pura summarize():
 * - rows: uma linha por recorrência do cadastro, sempre todas, com available (own + standard), own (do condomínio,
 *   ativos), standard (padrões ativos não encobertos), inactive_own, overridden_standard (padrões ativos encobertos),
 *   unused (à mão sem fatura no período), account_issue (à mão com conta que não vale), without_amount (à mão sem
 *   valor padrão), invoices e amount_cents (faturas não canceladas do período); totals soma as linhas;
 * - elsewhere: faturas do período de tipos que não estão à mão (inativos, encobertos ou excluídos), à parte;
 *   all_invoices e all_amount_cents somam as linhas e o elsewhere;
 * - unused_ids e account_issue_ids: até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * recorrências, contagens, valores e ids de tipos: nenhum nome de tipo, conta, observação nem unidade.
 */
final class InvoiceTypeOverview
{
    public const MAX_ROWS = 5000;
    public const MAX_IDS = 200;
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 60;

    private const COUNTERS = ['available', 'own', 'standard', 'inactive_own', 'overridden_standard', 'unused', 'account_issue', 'without_amount', 'invoices', 'amount_cents'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, string> as recorrências do cadastro */
    public static function recurrences(): array
    {
        return (array)RegistryService::definition('invoice_types')['fields']['recurrence']['options'];
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, standard:bool, recurrence:string, status:string, overridden:bool, account_ok:bool, has_amount:bool}> $types
     * @param array<int, array{count:int, amount_cents:int}> $invoices faturas não canceladas do período por tipo
     */
    public static function summarize(array $types, array $invoices): array
    {
        $recurrences = self::recurrences();
        $counts = array_fill_keys(array_keys($recurrences), array_fill_keys(self::COUNTERS, 0));
        $available = [];
        $unused = [];
        $issues = [];
        foreach ($types as $t) {
            $r = $t['recurrence'];
            if (!isset($counts[$r]) || ($t['standard'] && $t['status'] !== 'active')) {
                continue;
            }
            if (!$t['standard'] && $t['status'] !== 'active') {
                $counts[$r]['inactive_own']++;
                continue;
            }
            if ($t['standard'] && $t['overridden']) {
                $counts[$r]['overridden_standard']++;
                continue;
            }
            $counts[$r][$t['standard'] ? 'standard' : 'own']++;
            $counts[$r]['available']++;
            $available[$t['id']] = true;
            $used = $invoices[$t['id']] ?? ['count' => 0, 'amount_cents' => 0];
            $counts[$r]['invoices'] += $used['count'];
            $counts[$r]['amount_cents'] += $used['amount_cents'];
            if ($used['count'] === 0) {
                $counts[$r]['unused']++;
                $unused[] = $t['id'];
            }
            if (!$t['account_ok']) {
                $counts[$r]['account_issue']++;
                $issues[] = $t['id'];
            }
            if (!$t['has_amount']) {
                $counts[$r]['without_amount']++;
            }
        }
        $elsewhere = ['invoices' => 0, 'amount_cents' => 0];
        foreach ($invoices as $id => $used) {
            if (!isset($available[$id])) {
                $elsewhere['invoices'] += $used['count'];
                $elsewhere['amount_cents'] += $used['amount_cents'];
            }
        }
        $rows = [];
        $totals = array_fill_keys(self::COUNTERS, 0);
        foreach ($recurrences as $recurrence => $label) {
            $rows[] = ['recurrence' => $recurrence, 'recurrence_label' => $label] + $counts[$recurrence];
            foreach (self::COUNTERS as $key) {
                $totals[$key] += $counts[$recurrence][$key];
            }
        }
        sort($unused);
        sort($issues);
        return ['rows' => $rows, 'totals' => $totals, 'elsewhere' => $elsewhere,
            'all_invoices' => $totals['invoices'] + $elsewhere['invoices'], 'all_amount_cents' => $totals['amount_cents'] + $elsewhere['amount_cents'],
            'unused_ids' => array_slice($unused, 0, self::MAX_IDS), 'unused_truncated' => count($unused) > self::MAX_IDS,
            'account_issue_ids' => array_slice($issues, 0, self::MAX_IDS), 'account_issue_truncated' => count($issues) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, mixed $months = null, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $id = $int($condominiumId);
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $window = self::DEFAULT_MONTHS;
        if ($months !== null && $months !== '') {
            $window = $int($months);
            if ($window === false || $window > self::MAX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Meses: de 1 a %d.', self::MAX_MONTHS));
            }
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        // O mês do banco, para comparar com as competências gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $period = DiscountOverview::months($today, (int)$window);
        $definition = RegistryService::definition('invoice_types');
        $types = (string)$definition['table'];
        $override = (string)$definition['override'];
        $chart = (string)RegistryService::definition('chart')['table'];

        // Override da ADR 0163: um registro não excluído do condomínio com o mesmo valor encobre o padrão.
        // Conta que vale: a do cadastro (analítica, de receita, ativa, não excluída, do padrão ou do condomínio).
        $stmt = $this->pdo->prepare("SELECT t.id, t.condominium_id, t.recurrence, t.status, t.default_amount_cents,
                EXISTS (SELECT 1 FROM {$types} o WHERE o.condominium_id = ? AND o.deleted_at IS NULL AND o.{$override} = t.{$override}) AS overridden,
                CASE WHEN c.id IS NOT NULL AND c.deleted_at IS NULL AND c.status = 'active' AND c.kind = 'analitica' AND c.nature = 'receita'
                    AND (c.condominium_id IS NULL OR c.condominium_id = ?) THEN 1 ELSE 0 END AS account_ok
            FROM {$types} t LEFT JOIN {$chart} c ON c.id = t.chart_account_id
            WHERE t.deleted_at IS NULL AND (t.condominium_id = ? OR t.condominium_id IS NULL) ORDER BY t.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, (int)$id, (int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d tipos de faturas: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT invoice_type_id, COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS total FROM erp_invoices
            WHERE condominium_id = ? AND status <> 'cancelled' AND competence BETWEEN ? AND ? GROUP BY invoice_type_id");
        $stmt->execute([(int)$id, $period[0], $period[count($period) - 1]]);
        $invoices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $invoices[(int)$r['invoice_type_id']] = ['count' => (int)$r['n'], 'amount_cents' => (int)$r['total']];
        }
        return ['condominium_id' => (int)$id, 'months' => (int)$window, 'from' => $period[0], 'to' => $period[count($period) - 1]] + self::summarize(
            array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'standard' => $r['condominium_id'] === null, 'recurrence' => (string)$r['recurrence'],
                'status' => (string)$r['status'], 'overridden' => (bool)$r['overridden'], 'account_ok' => (bool)$r['account_ok'],
                'has_amount' => $r['default_amount_cents'] !== null], $rows),
            $invoices
        );
    }
}
