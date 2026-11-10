<?php
/**
 * Tela única dos relatórios com serviço e API (ADR 0143): gerenciais, de pagamentos, cadastrais e o
 * Balancete Anual. O controlador (ReportScreens) entrega a definição da página, os filtros, as linhas e
 * a URL do CSV; a tela só desenha os filtros e formata as células. "Exportar CSV" baixa a mesma consulta
 * pela API (format=csv), com as células protegidas contra fórmula e a exportação na auditoria.
 * @var array $currentPage @var array $reportPage @var array $reportFilters @var ?string $reportError @var ?string $reportNotice
 * @var ?array $report @var list<array> $reportRows @var ?string $reportCsv @var bool $reportNeedsCondo @var array $reportOptions
 */
use Source\Controllers\Erp\Connect\ReportScreens as Screens;

$this->layout('default');
$route = erp_url($currentPage['route']);
$condo = \Source\Support\Erp\CondominiumContext::current();
$scopeLabel = ($reportPage['scope'] ?? null) === 'all' || $condo === null ? 'Todos os condomínios do seu escopo' : (string)$condo['name'];
$f = $reportFilters;
$filters = $reportPage['filters'];
$columns = $reportPage['columns'];
$has = static fn (string $name): bool => in_array($name, $filters, true);
$competences = $has('competences') || $has('compare');
$periodText = match (true) {
    $has('compare') => 'A: ' . $f['de'] . ' a ' . $f['ate'] . ' · B: ' . $f['de_b'] . ' a ' . $f['ate_b'],
    $competences => 'competências ' . $f['de'] . ' a ' . $f['ate'],
    $has('period') => 'de ' . Screens::brDate($f['de']) . ' a ' . Screens::brDate($f['ate']),
    $has('competence') => 'competência ' . $f['competencia'] . ($f['comparar'] !== null ? ' × ' . $f['comparar'] : ''),
    $has('until') => 'até ' . Screens::brDate($f['limite']),
    $has('as_of') => 'em ' . Screens::brDate($f['referencia']),
    $has('year') => 'ano ' . $f['ano'],
    default => 'situação de hoje',
};
$select = function (string $name, array $options, $current, string $label): string {
    $html = '<div class="erp-field erp-field--compact"><label for="rp-' . $name . '">' . $this->e($label) . '</label><select class="erp-select" id="rp-' . $name . '" name="' . $name . '">';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . $this->e((string)$value) . '"' . ((string)$current === (string)$value ? ' selected' : '') . '>' . $this->e((string)$text) . '</option>';
    }
    return $html . '</select></div>';
};
$input = fn (string $name, string $type, string $label, $value): string => '<div class="erp-field erp-field--compact"><label for="rp-' . $name . '">' . $this->e($label) . '</label><input class="erp-input" type="' . $type . '" id="rp-' . $name . '" name="' . $name . '" value="' . $this->e((string)$value) . '" required></div>';
$totals = Screens::totals($report, $reportPage['totalLabels'] ?? []);

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
    <?php if ($reportCsv !== null && $reportRows !== [] && $reportError === null): ?><a class="erp-btn erp-btn--primary" href="<?= $this->e(url($reportCsv)) ?>" download><?= $icon('download', 'erp-icon--sm') ?>Exportar CSV</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => $reportPage['lead'],
    'pageActions' => $actions,
]);
?>
<?php if ($reportNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>Este relatório usa os dados de um condomínio só (a fração ideal ou a regra de cobrança dele). Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<?php if ($filters !== []): ?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtros do relatório">
    <?php foreach ($filters as $filter): ?>
        <?= match ($filter) {
            'period' => $input('de', 'date', 'De', $f['de']) . $input('ate', 'date', 'Até', $f['ate']),
            'competences' => $input('de', 'month', 'Competência inicial', $f['de']) . $input('ate', 'month', 'Competência final', $f['ate']),
            'compare' => $input('de', 'month', 'Período A: de', $f['de']) . $input('ate', 'month', 'Período A: até', $f['ate']) . $input('de_b', 'month', 'Período B: de', $f['de_b']) . $input('ate_b', 'month', 'Período B: até', $f['ate_b']),
            'competence' => $input('competencia', 'month', 'Competência', $f['competencia']),
            'compare_with' => $input('comparar', 'month', 'Comparar com', $f['comparar']),
            'until' => $input('limite', 'date', 'Vencimento até', $f['limite']),
            'as_of' => $input('referencia', 'date', 'Data de referência', $f['referencia']),
            'year' => $input('ano', 'number', 'Ano', $f['ano']),
            'account_code' => '<div class="erp-field erp-field--compact"><label for="rp-codigo">Conta do plano (código)</label><input class="erp-input" type="text" id="rp-codigo" name="codigo" value="' . $this->e($f['codigo']) . '" placeholder="Todas" inputmode="decimal" pattern="\d+(\.\d+)*"></div>',
            'bank_account' => $select('conta', array_column($reportOptions['bank_account'], 'label', 'id'), $f['conta'], 'Conta bancária'),
            'unit' => $select('unidade', ($currentPageId === 'AP-065' ? [] : ['' => 'Todas']) + array_column($reportOptions['unit'], 'label', 'id'), $f['unidade'], 'Unidade'),
            default => isset(Screens::SELECTS[$filter]) ? $select($filter, Screens::SELECTS[$filter][2], $f[$filter], Screens::SELECTS[$filter][0]) : '',
        } ?>
    <?php endforeach; ?>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>
<?php endif; ?>

<?php if ($reportError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível gerar o relatório</strong><?= $this->e($reportError) ?></div></div>
<?php else: ?>
    <?php if ($reportNotice !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= in_array($currentPageId, ['AP-062', 'AP-063', 'AP-064', 'AP-065', 'AP-066', 'AP-067'], true) ? 'Rateio não calculado' : 'Atenção' ?></strong><?= $this->e($reportNotice) ?></div></div>
    <?php endif; ?>
    <?php if (!empty($reportPage['personal'])): ?>
        <div class="erp-alert" role="note"><?= $icon('lock') ?><div>Dados pessoais: documento, e-mail, telefone e IP aparecem mascarados, e a exportação fica registrada na auditoria.</div></div>
    <?php endif; ?>
    <?php if (isset($report['note'])): ?>
        <div class="erp-alert" role="note"><?= $icon('info') ?><div><?= $this->e((string)$report['note']) ?></div></div>
    <?php endif; ?>
    <?php if (isset($report['rule']['formula'])): ?>
        <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Regra de cobrança</strong>Multa <?= $this->e(number_format($report['rule']['fine_bp'] / 100, 2, ',', '.')) ?>%, juros <?= $this->e(number_format($report['rule']['monthly_interest_bp'] / 100, 2, ',', '.')) ?>% ao mês, índice <?= $this->e((string)$report['rule']['price_index']) ?>. <?= $this->e((string)$report['rule']['formula']) ?>.<?php if (($report['totals']['missing_index'] ?? []) !== []): ?> Meses sem taxa do índice (correção zero): <?= $this->e(implode(', ', $report['totals']['missing_index'])) ?>.<?php endif; ?></div></div>
    <?php endif; ?>
    <?php if ($totals !== [] && $reportRows !== []): ?>
        <section class="erp-grid erp-grid--kpi" aria-label="Totais do relatório">
            <?php foreach (array_slice($totals, 0, 6) as $i => [$label, $cents]): $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => Screens::brl($cents), 'meta' => $scopeLabel, 'icon' => 'chart', 'tone' => $cents < 0 ? 'danger' : '', 'trend' => null], 'delay' => $i * 40]); endforeach; ?>
        </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="rp-title">
        <div class="erp-card__head"><div><h2 id="rp-title"><?= $this->e($currentPage['name']) ?></h2><p><?= $this->e($scopeLabel) ?> · <?= $this->e($periodText) ?> · <?= count($reportRows) ?> linha(s) · emitido em <?= date('d/m/Y') ?></p></div></div>
        <?php if ($reportRows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nada neste filtro</strong><p>Não há registros para os filtros escolhidos.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="rp-title" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><?php foreach ($columns as $c): ?><th scope="col"<?= Screens::numeric($c[2]) ? ' class="is-num"' : '' ?>><?= $this->e($c[0]) ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($reportRows as $row): $class = Screens::rowClass($row); ?><tr<?= $class !== '' ? ' class="' . $class . '"' : '' ?>><?php foreach ($columns as $c): ?><td<?= in_array($c[2], ['text', 'tree'], true) ? '' : ' class="' . (Screens::numeric($c[2]) ? 'is-num ' : '') . 'erp-nowrap"' ?>><?= Screens::cell($row, $c) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                <?php if ($currentPageId === 'AP-080' && isset($report['totals']['result_months_cents'])): $t = $report['totals']; ?>
                    <tfoot><?php foreach ([['Total de receitas', 'revenue'], ['Total de despesas', 'expense'], ['Resultado', 'result']] as [$label, $key]): ?>
                        <tr><th scope="row" colspan="3"><?= $label ?></th><?php foreach ($t[$key . '_months_cents'] as $cents): ?><td class="is-num erp-nowrap"><?= $this->e(Screens::brl((int)$cents)) ?></td><?php endforeach; ?><td class="is-num erp-nowrap"><?= $this->e(Screens::brl((int)$t[$key . '_cents'])) ?></td></tr>
                    <?php endforeach; ?></tfoot>
                <?php endif; ?>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php endif; ?>
