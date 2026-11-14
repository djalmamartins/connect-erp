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
 * Conferência entre as faturas e as contas a receber (ADR 0400), somente leitura. A fatura (AP-032, ADR 0033) cobra um
 * tipo de fatura de todas as unidades numa competência: preparada, ela guarda um item por unidade; emitida, cada item vira
 * uma conta a receber (erp_entries.invoice_item_id, chave única); cancelada, as contas da fatura ficam inativas. Depois
 * disso as contas seguem a vida própria (baixa, acordo, edição, exclusão), e ninguém confere, fatura por fatura, se o que
 * foi faturado continua batendo com o que está lançado. A conferência do faturamento (ADR 0198) olha a unidade na
 * competência, não a fatura.
 *
 * Reusa o que já existe: as situações das faturas (Invoices::STATUSES), a ligação item → conta (invoice_item_id), as
 * regras do cancelamento (Invoices::cancel inativa as contas da fatura emitida; conta paga ou em acordo impede o
 * cancelamento), a conta renegociada em acordo (agreement_id, ADR 0039), o cadastro de lançamentos
 * (RegistryService::definition('entries'): a tabela e a permissão) e o período por competência (DiscountOverview::months(),
 * no mês do banco).
 *
 * Regra pura classify(), por item: na fatura emitida, missing (sem conta, ou a conta foi excluída), renegotiated (conta em
 * acordo), inactive (conta inativa fora de acordo), amount_mismatch (conta ativa com valor diferente do item), paid (ativa,
 * paga, mesmo valor) ou open (ativa, em aberto, mesmo valor); na cancelada, still_active quando a conta segue ativa; na
 * preparação, nenhuma classe (ainda não há conta).
 *
 * Regra pura summarize():
 * - rows: uma linha por situação de fatura, sempre todas, com invoices, items, amount_cents (soma dos itens),
 *   total_mismatch (faturas cujo total ou quantidade de unidades gravados não batem com os itens), overdue_drafts
 *   (preparações com vencimento já passado) e as classes dos itens; totals soma as linhas;
 * - attention_ids: faturas com total que não bate, preparação vencida ou algum item missing, inactive, amount_mismatch ou
 *   still_active, até MAX_IDS, com truncated. Pago, em aberto e renegociado não pedem atenção.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * situações, contagens, valores e ids de faturas: nenhuma unidade, morador, descrição nem conta bancária.
 */
final class InvoiceReconciliation
{
    public const MAX_ROWS = 20000;
    public const MAX_IDS = 200;
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 60;

    public const CLASSES = ['missing', 'renegotiated', 'inactive', 'amount_mismatch', 'paid', 'open', 'still_active'];
    private const ATTENTION = ['missing', 'inactive', 'amount_mismatch', 'still_active'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Classe do item (regra pura); null quando não há o que conferir.
     *
     * @param array{status:string, amount_cents:int, paid:bool, agreement:bool}|null $entry a conta do item, não excluída
     */
    public static function classify(string $invoiceStatus, int $itemCents, ?array $entry): ?string
    {
        if ($invoiceStatus === 'cancelled') {
            return $entry !== null && $entry['status'] === 'active' ? 'still_active' : null;
        }
        if ($invoiceStatus !== 'issued') {
            return null;
        }
        return match (true) {
            $entry === null => 'missing',
            $entry['agreement'] => 'renegotiated',
            $entry['status'] !== 'active' => 'inactive',
            $entry['amount_cents'] !== $itemCents => 'amount_mismatch',
            $entry['paid'] => 'paid',
            default => 'open',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, status:string, due_on:string, units_count:int, amount_cents:int}> $invoices
     * @param list<array{invoice_id:int, amount_cents:int, entry:?array}> $items
     */
    public static function summarize(array $invoices, array $items, string $today): array
    {
        $counters = array_merge(['invoices', 'items', 'amount_cents', 'total_mismatch', 'overdue_drafts'], self::CLASSES);
        $statuses = Invoices::STATUSES;
        $counts = array_fill_keys(array_keys($statuses), array_fill_keys($counters, 0));
        $byInvoice = [];
        foreach ($items as $item) {
            $byInvoice[$item['invoice_id']][] = $item;
        }
        $attention = [];
        foreach ($invoices as $invoice) {
            $status = $invoice['status'];
            if (!isset($counts[$status])) {
                continue;
            }
            $own = $byInvoice[$invoice['id']] ?? [];
            $sum = array_sum(array_column($own, 'amount_cents'));
            $counts[$status]['invoices']++;
            $counts[$status]['items'] += count($own);
            $counts[$status]['amount_cents'] += $sum;
            $flag = false;
            if ($sum !== $invoice['amount_cents'] || count($own) !== $invoice['units_count']) {
                $counts[$status]['total_mismatch']++;
                $flag = true;
            }
            if ($status === 'draft' && $invoice['due_on'] < $today) {
                $counts[$status]['overdue_drafts']++;
                $flag = true;
            }
            foreach ($own as $item) {
                $class = self::classify($status, $item['amount_cents'], $item['entry']);
                if ($class !== null) {
                    $counts[$status][$class]++;
                    $flag = $flag || in_array($class, self::ATTENTION, true);
                }
            }
            if ($flag) {
                $attention[] = $invoice['id'];
            }
        }
        $rows = [];
        $totals = array_fill_keys($counters, 0);
        foreach ($statuses as $status => $label) {
            $rows[] = ['status' => $status, 'status_label' => $label] + $counts[$status];
            foreach ($counters as $key) {
                $totals[$key] += $counts[$status][$key];
            }
        }
        sort($attention);
        return ['rows' => $rows, 'totals' => $totals,
            'attention_ids' => array_slice($attention, 0, self::MAX_IDS), 'attention_truncated' => count($attention) > self::MAX_IDS];
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

        // O dia e o mês do banco, para comparar com as datas e as competências gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $period = DiscountOverview::months($today, (int)$window);
        $from = $period[0];
        $to = $period[count($period) - 1];
        $entries = (string)RegistryService::definition('entries')['table'];

        $stmt = $this->pdo->prepare('SELECT id, status, due_on, units_count, amount_cents FROM erp_invoices WHERE condominium_id = ? AND competence BETWEEN ? AND ?
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($invoices) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d faturas no período: escolha menos meses.', self::MAX_ROWS));
        }
        // A conta do item (chave única em invoice_item_id); a excluída conta como sem conta.
        $stmt = $this->pdo->prepare("SELECT it.invoice_id, it.amount_cents AS item_cents, e.id AS entry_id, e.status AS entry_status,
                e.amount_cents AS entry_cents, CASE WHEN e.paid_on IS NULL THEN 0 ELSE 1 END AS paid, CASE WHEN e.agreement_id IS NULL THEN 0 ELSE 1 END AS agreement
            FROM erp_invoice_items it JOIN erp_invoices i ON i.id = it.invoice_id
            LEFT JOIN {$entries} e ON e.invoice_item_id = it.id AND e.deleted_at IS NULL
            WHERE i.condominium_id = ? AND i.competence BETWEEN ? AND ? ORDER BY it.id LIMIT " . (self::MAX_ROWS * 50 + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $items = array_map(static fn (array $r): array => ['invoice_id' => (int)$r['invoice_id'], 'amount_cents' => (int)$r['item_cents'],
            'entry' => $r['entry_id'] === null ? null : ['status' => (string)$r['entry_status'], 'amount_cents' => (int)$r['entry_cents'],
                'paid' => (bool)$r['paid'], 'agreement' => (bool)$r['agreement']]], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['condominium_id' => (int)$id, 'months' => (int)$window, 'from' => $from, 'to' => $to] + self::summarize(
            array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'due_on' => substr((string)$r['due_on'], 0, 10),
                'units_count' => (int)$r['units_count'], 'amount_cents' => (int)$r['amount_cents']], $invoices),
            $items,
            $today
        );
    }
}
