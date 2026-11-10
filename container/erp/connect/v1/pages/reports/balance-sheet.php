<?php
/**
 * Balanço Patrimonial (AP-081; ADR 0148, sobre o ADR 0167) com dados reais do condomínio da barra superior:
 * ativo, passivo e patrimônio líquido na data, contas bancárias do Disponível, vencido de receber e pagar,
 * diferença e avisos. Somente leitura; o CSV sai da API GET /api/v1/reports/balance-sheet.
 * @var array $currentPage @var string $balanceDate @var bool $balanceNeedsCondo @var ?string $balanceError @var ?array $balance
 */
use Source\Controllers\Erp\Connect\BalanceSheetScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;

$this->layout('default');
$route = erp_url($currentPage['route']);
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condo['name'] ?? 'o condomínio');
$b = $balance;
$link = static fn (string $page, string $fallback): string => erp_url($catalog['pages'][$page]['route'] ?? $fallback);

ob_start(); ?>
    <?php if ($b !== null): ?><a class="erp-btn" href="<?= $this->e('/api/v1/reports/balance-sheet?' . http_build_query(['format' => 'csv', 'condominium_id' => (int)$b['condominium_id'], 'date' => $b['date']])) ?>"><?= $icon('download', 'erp-icon--sm') ?>Exportar CSV</a><?php endif; ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'O que o condomínio tem e o que deve numa data: saldos bancários, contas a receber e a pagar, fundos e o resultado acumulado.',
    'pageActions' => $actions,
]);
?>
<?php if ($balanceNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>O balanço é de um condomínio só. Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Data do balanço">
    <div class="erp-field erp-field--compact"><label for="bp-date">Data do balanço</label><input class="erp-input" type="date" id="bp-date" name="data" value="<?= $this->e($balanceDate) ?>" min="2000-01-01" max="<?= date('Y-m-d') ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($balanceError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível montar o balanço</strong><?= $this->e($balanceError) ?></div></div>
<?php elseif ($b !== null): $t = $b['totals']; ?>
    <section class="erp-card" aria-labelledby="bp-title">
        <div class="erp-card__head"><div><h2 id="bp-title">Balanço em <?= $this->e(RS::brDate($b['date'])) ?></h2><p><?= $this->e($condoName) ?> · resultado do exercício desde <?= $this->e(RS::brDate($b['year_start'])) ?> · emitido em <?= date('d/m/Y') ?></p></div>
            <span class="erp-badge erp-badge--<?= $b['balanced'] ? 'success' : 'warning' ?>"><?= $b['balanced'] ? 'Fechado' : 'Diferença de ' . $this->e(RS::brl((int)$b['diferenca'])) ?></span></div>
        <?php foreach ($b['warnings'] as $w): ?>
            <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><?= $this->e($w['message']) ?></div></div>
        <?php endforeach; ?>
        <?php if (!empty($b['truncated'])): ?><div class="erp-alert" role="note"><?= $icon('info') ?><div>O plano ou os lançamentos passaram do limite de linhas; os valores podem estar incompletos.</div></div><?php endif; ?>
    </section>

    <section class="erp-grid erp-grid--kpi" aria-label="Totais do balanço">
        <?php foreach ([
            ['Ativo', $t['assets_cents'], ''], ['Passivo', $t['liabilities_cents'], ''], ['Patrimônio líquido', $t['equity_cents'], $t['equity_cents'] < 0 ? 'danger' : ''],
            ['Passivo + patrimônio líquido', $t['liabilities_and_equity_cents'], ''], ['Diferença', $b['diferenca'], $b['diferenca'] !== 0 ? 'warning' : ''],
        ] as $i => [$label, $cents, $tone]): $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => RS::brl((int)$cents), 'meta' => 'em ' . RS::brDate($b['date']), 'icon' => 'scale', 'tone' => $tone, 'trend' => null], 'delay' => $i * 40]); endforeach; ?>
    </section>

    <?php foreach (Screens::GROUPS as $key => [$title, $totalKey]): $rows = Screens::rows($b[$key]); $id = 'bp-' . $key; ?>
    <section class="erp-card" aria-labelledby="<?= $id ?>">
        <div class="erp-card__head"><div><h2 id="<?= $id ?>"><?= $this->e($title) ?></h2><p>Total: <?= $this->e(RS::brl((int)$t[$totalKey])) ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('scale') ?></span><strong>Nenhuma conta neste grupo</strong><p>O plano de contas não tem conta ativa deste grupo.</p></div>
        <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="<?= $id ?>" tabindex="0"><table class="erp-table erp-report-table">
            <thead><tr><th scope="col">Código</th><th scope="col">Conta</th><th scope="col" class="is-num">Vencido</th><th scope="col" class="is-num">Saldo</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $class = !empty($row['detail']) ? 'erp-muted' : RS::rowClass($row); ?>
                <tr<?= $class !== '' ? ' class="' . $class . '"' : '' ?>>
                    <td class="erp-nowrap"><?= $this->e((string)$row['code']) ?></td>
                    <td><?= RS::cell($row, ['Conta', 'name', 'tree']) ?></td>
                    <td class="is-num erp-nowrap"><?= isset($row['overdue_cents']) ? $this->e(RS::brl((int)$row['overdue_cents'])) : '' ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$row['amount_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="3">Total do <?= $this->e(mb_strtolower($title)) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$t[$totalKey])) ?></td></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <section class="erp-card" aria-labelledby="bp-limits">
        <div class="erp-card__head"><div><h2 id="bp-limits">Como o balanço é calculado</h2><p>Os valores saem do que o sistema já registra. Para ajustar fundos, saldos bancários e o PL, use o <a href="<?= $this->e($link('AP-161', '/configuracoes/saldo-inicial-plano-de-contas')) ?>">saldo inicial do plano de contas</a>.</p></div></div>
        <ul class="erp-field__hint"><?php foreach ($b['limitations'] as $l): ?><li><?= $this->e($l) ?></li><?php endforeach; ?></ul>
    </section>
<?php endif; ?>
<?php endif; ?>
