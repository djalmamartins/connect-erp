<?php
/**
 * Prestação de contas mensal (AP-101; ADR 0146, sobre o ADR 0166) com dados reais do condomínio da barra
 * superior: resumo do mês, avisos, fechamento, DRE por competência, saldos bancários e inadimplência no fim do
 * mês. Somente leitura, para tela e impressão; o CSV de cada parte está no relatório de origem.
 * @var array $currentPage @var string $accountabilityMonth @var bool $accountabilityNeedsCondo @var ?string $accountabilityError @var ?array $accountability
 */
use Source\Controllers\Erp\Connect\AccountabilityScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;

$this->layout('default');
$route = erp_url($currentPage['route']);
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condo['name'] ?? 'o condomínio');
$p = $accountability;
$link = static fn (string $page, string $fallback): string => erp_url($catalog['pages'][$page]['route'] ?? $fallback);
$closingLabels = ['closed' => ['Mês fechado', 'success'], 'reopened' => ['Reaberto', 'warning'], 'open' => ['Mês em aberto', 'warning']];
$table = function (string $id, array $columns, array $rows): string {
    $html = '<div class="erp-table-wrap" role="region" aria-labelledby="' . $id . '" tabindex="0"><table class="erp-table erp-report-table"><thead><tr>';
    foreach ($columns as $c) {
        $html .= '<th scope="col"' . (RS::numeric($c[2]) ? ' class="is-num"' : '') . '>' . $this->e($c[0]) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $class = RS::rowClass($row);
        $html .= '<tr' . ($class !== '' ? ' class="' . $class . '"' : '') . '>';
        foreach ($columns as $c) {
            $html .= '<td' . (in_array($c[2], ['text', 'tree'], true) ? '' : ' class="' . (RS::numeric($c[2]) ? 'is-num ' : '') . 'erp-nowrap"') . '>' . RS::cell($row, $c) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
};

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'O pacote do mês de um condomínio: resultado, saldos bancários, inadimplência e a situação do fechamento, para conferir e imprimir.',
    'pageActions' => $actions,
]);
?>
<?php if ($accountabilityNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>A prestação de contas é de um condomínio só. Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Mês da prestação de contas">
    <div class="erp-field erp-field--compact"><label for="pc-month">Mês</label><input class="erp-input" type="month" id="pc-month" name="mes" value="<?= $this->e($accountabilityMonth) ?>" max="<?= date('Y-m') ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($accountabilityError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível montar a prestação de contas</strong><?= $this->e($accountabilityError) ?></div></div>
<?php elseif ($p !== null): $s = $p['summary']; [$closingLabel, $closingTone] = $closingLabels[$p['closing']['status']] ?? [$p['closing']['status'], 'plain']; ?>
    <section class="erp-card" aria-labelledby="pc-title">
        <div class="erp-card__head"><div><h2 id="pc-title"><?= $this->e(Screens::monthLabel($p['competence'])) ?></h2><p><?= $this->e($condoName) ?> · de <?= $this->e(RS::brDate($p['from'])) ?> a <?= $this->e(RS::brDate($p['to'])) ?> · emitido em <?= date('d/m/Y') ?></p></div><span class="erp-badge erp-badge--<?= $closingTone ?>"><?= $this->e($closingLabel) ?></span></div>
        <?php if ($p['closing']['closed_at'] !== null): ?><p class="erp-field__hint">Fechado em <?= $this->e(RS::brDate((string)$p['closing']['closed_at'], true)) ?>.<?= $p['closing']['reopen_reason'] ? ' Reaberto: ' . $this->e((string)$p['closing']['reopen_reason']) . '.' : '' ?></p><?php endif; ?>
        <?php foreach ($p['warnings'] as $w): ?>
            <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><?= $this->e($w['message']) ?></div></div>
        <?php endforeach; ?>
        <?php if (!empty($p['truncated'])): ?><div class="erp-alert" role="note"><?= $icon('info') ?><div>Parte do pacote foi cortada pelo limite de linhas. Os relatórios de origem trazem tudo.</div></div><?php endif; ?>
    </section>

    <section class="erp-grid erp-grid--kpi" aria-label="Resumo do mês">
        <?php foreach ([
            ['Receitas (competência)', $s['revenue_cents'], ''], ['Despesas (competência)', $s['expense_cents'], ''], ['Resultado', $s['result_cents'], $s['result_cents'] < 0 ? 'danger' : ''],
            ['Entradas no caixa', $s['cash_in_cents'], ''], ['Saídas do caixa', $s['cash_out_cents'], ''], ['Saldo bancário no fim do mês', $s['bank_closing_cents'], $s['bank_closing_cents'] < 0 ? 'danger' : ''],
            ['Receitas em aberto', $s['open_revenue_cents'], $s['open_revenue_cents'] > 0 ? 'warning' : ''], ['Despesas em aberto', $s['open_expense_cents'], $s['open_expense_cents'] > 0 ? 'warning' : ''],
            ['Inadimplência no fim do mês', $s['delinquent_cents'], $s['delinquent_cents'] > 0 ? 'danger' : ''],
        ] as $i => [$label, $cents, $tone]): $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => RS::brl((int)$cents), 'meta' => Screens::monthLabel($p['competence']), 'icon' => 'chart', 'tone' => $tone, 'trend' => null], 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="pc-income-title">
        <div class="erp-card__head"><div><h2 id="pc-income-title">Demonstração do resultado</h2><p>Receitas e despesas da competência por conta do plano. <a href="<?= $this->e($link('AP-082', '/relatorios/dre') . '?de=' . $p['from'] . '&ate=' . $p['to'] . '&base=competence') ?>">Abrir a DRE</a> · <a href="<?= $this->e($link('AP-079', '/prestacao-de-contas/balancete') . '?de=' . $p['competence'] . '&ate=' . $p['competence']) ?>">Abrir o balancete</a></p></div></div>
        <?= $p['income_statement']['rows'] === [] ? '<div class="erp-empty"><span class="erp-empty__icon">' . $icon('chart') . '</span><strong>Nenhum lançamento na competência</strong><p>Não há receita nem despesa neste mês.</p></div>' : $table('pc-income-title', Screens::INCOME_COLUMNS, $p['income_statement']['rows']) ?>
    </section>

    <section class="erp-card" aria-labelledby="pc-bank-title">
        <div class="erp-card__head"><div><h2 id="pc-bank-title">Saldos bancários</h2><p>Saldo no início do mês, entradas, saídas e saldo no fim. <a href="<?= $this->e($link('AP-073', '/relatorios/saldos-de-contas')) ?>">Abrir os saldos de contas</a></p></div></div>
        <?= $p['bank_accounts']['accounts'] === [] ? '<div class="erp-empty"><span class="erp-empty__icon">' . $icon('wallet') . '</span><strong>Nenhuma conta bancária</strong><p>Cadastre as contas bancárias do condomínio.</p></div>' : $table('pc-bank-title', Screens::BANK_COLUMNS, $p['bank_accounts']['accounts']) ?>
    </section>

    <section class="erp-card" aria-labelledby="pc-delinquency-title">
        <div class="erp-card__head"><div><h2 id="pc-delinquency-title">Inadimplência em <?= $this->e(RS::brDate($p['delinquency']['as_of'])) ?></h2><p><?= (int)$p['delinquency']['totals']['units'] ?> unidade(s) · <?= (int)$p['delinquency']['totals']['entries'] ?> conta(s) · <?= $this->e(RS::brl((int)$p['delinquency']['totals']['amount_cents'])) ?> sem encargos<?= !empty($p['delinquency']['truncated']) ? ' · mostrando as ' . count($p['delinquency']['units']) . ' maiores' : '' ?>.</p></div></div>
        <?= $p['delinquency']['units'] === [] ? '<div class="erp-empty"><span class="erp-empty__icon">' . $icon('check') . '</span><strong>Nenhuma unidade em atraso</strong><p>Não havia conta vencida em aberto no fim do mês.</p></div>' : $table('pc-delinquency-title', Screens::DELINQUENCY_COLUMNS, $p['delinquency']['units']) ?>
    </section>
<?php endif; ?>
<?php endif; ?>
