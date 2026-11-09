<?php
/**
 * Balancete de verificação (AP-079, ADR 0037) com dados reais: $trialBalance de Panel::reportData,
 * no contexto da barra superior, por competência (?de=AAAA-MM&ate=AAAA-MM, até 24 meses). Cada conta
 * do plano com previsto, realizado e em aberto; as analíticas somam nas sintéticas.
 * @var array $currentPage @var ?array $trialBalance @var ?string $reportError @var array{from:string,to:string} $reportPeriod
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$month = static fn (string $value): string => preg_match('/^\d{4}-\d{2}$/', $value) ? substr($value, 5, 2) . '/' . substr($value, 0, 4) : $value;
$condo = \Source\Support\Erp\CondominiumContext::current();
$scopeLabel = $condo !== null ? (string)$condo['name'] : 'Todos os condomínios do seu escopo';
$rows = $trialBalance['rows'] ?? [];
$totals = $trialBalance['totals'] ?? [];
$t = static fn (string $key): int => (int)($totals[$key] ?? 0);
$natures = ['receita' => ['Receitas', 'revenue'], 'despesa' => ['Despesas', 'expense']];

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Lançamentos válidos por conta do plano, pela competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.',
    'pageActions' => $actions,
]);
?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Período do balancete">
    <div class="erp-field erp-field--compact"><label for="tb-from">De (competência)</label><input class="erp-input" type="month" id="tb-from" name="de" value="<?= $this->e($reportPeriod['from']) ?>" required></div>
    <div class="erp-field erp-field--compact"><label for="tb-to">Até (competência)</label><input class="erp-input" type="month" id="tb-to" name="ate" value="<?= $this->e($reportPeriod['to']) ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($reportError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível gerar o balancete</strong><?= $this->e($reportError) ?></div></div>
<?php else: ?>
    <section class="erp-grid erp-grid--kpi" aria-label="Resumo do balancete">
        <?php foreach ([
            ['label' => 'Receitas realizadas', 'value' => $brl($t('realized_revenue_cents')), 'meta' => 'de ' . $brl($t('planned_revenue_cents')) . ' previstas', 'icon' => 'arrow-down', 'tone' => '', 'trend' => null],
            ['label' => 'Despesas realizadas', 'value' => $brl($t('realized_expense_cents')), 'meta' => 'de ' . $brl($t('planned_expense_cents')) . ' previstas', 'icon' => 'arrow-up', 'tone' => 'warning', 'trend' => null],
            ['label' => 'Resultado realizado', 'value' => $brl($t('realized_result_cents')), 'meta' => 'Previsto: ' . $brl($t('planned_result_cents')), 'icon' => 'scale', 'tone' => $t('realized_result_cents') < 0 ? 'danger' : 'info', 'trend' => null],
            ['label' => 'Em aberto', 'value' => $brl($t('open_revenue_cents') + $t('open_expense_cents')), 'meta' => $brl($t('open_revenue_cents')) . ' a receber · ' . $brl($t('open_expense_cents')) . ' a pagar', 'icon' => 'clock', 'tone' => '', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="tb-title">
        <div class="erp-card__head"><div><h2 id="tb-title">Balancete de <?= $this->e($month($reportPeriod['from'])) ?><?= $reportPeriod['from'] !== $reportPeriod['to'] ? ' a ' . $this->e($month($reportPeriod['to'])) : '' ?></h2><p><?= $this->e($scopeLabel) ?> · regime de competência · emitido em <?= date('d/m/Y') ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('scale') ?></span><strong>Nenhum lançamento no período</strong><p>Não há lançamentos válidos nestas competências.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="tb-title" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><th>Conta</th><th class="is-num">Previsto</th><th class="is-num">Realizado</th><th class="is-num">Em aberto</th></tr></thead>
                <?php foreach ($natures as $nature => [$label, $suffix]): $group = array_values(array_filter($rows, static fn (array $r): bool => $r['nature'] === $nature)); if ($group === []) continue; ?>
                    <tbody>
                        <tr class="erp-report-group"><th scope="rowgroup" colspan="4"><?= $label ?></th></tr>
                        <?php foreach ($group as $row): ?>
                            <tr class="<?= $row['analytic'] ? '' : 'erp-report-synthetic' ?>">
                                <td style="--erp-level: <?= max(0, (int)$row['level'] - 1) ?>"><span class="erp-report-account"><span class="erp-report-code"><?= $this->e((string)$row['code']) ?></span> <?= $this->e((string)$row['name']) ?></span></td>
                                <td class="is-num"><?= $brl((int)$row['planned_cents']) ?></td>
                                <td class="is-num"><?= $brl((int)$row['realized_cents']) ?></td>
                                <td class="is-num"><?= $brl((int)$row['open_cents']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="erp-report-total"><td>Total de <?= mb_strtolower($label) ?></td><td class="is-num"><?= $brl($t("planned_{$suffix}_cents")) ?></td><td class="is-num"><?= $brl($t("realized_{$suffix}_cents")) ?></td><td class="is-num"><?= $brl($t("open_{$suffix}_cents")) ?></td></tr>
                    </tbody>
                <?php endforeach; ?>
                <tfoot><tr><td>Resultado (receitas − despesas)</td><td class="is-num"><?= $brl($t('planned_result_cents')) ?></td><td class="is-num"><?= $brl($t('realized_result_cents')) ?></td><td class="is-num"><?= $brl($t('open_revenue_cents') - $t('open_expense_cents')) ?></td></tr></tfoot>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
