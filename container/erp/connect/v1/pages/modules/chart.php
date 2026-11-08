<?php
/** Gráfico com parâmetros e tabela equivalente. @var array $currentPage @var array $chart */
$this->layout('default');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ' · Gráficos',
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Evolução mensal no período escolhido.',
    'pageActions' => '<button type="button" class="erp-btn" data-erp-pending="Exportação pendente: será gerada com os dados reais.">' . $icon('download', 'erp-icon--sm') . 'Exportar</button>',
]);
$this->insert('components/notifications/proposal');
?>
<form class="erp-filters" onsubmit="return false" aria-label="Parâmetros do gráfico">
    <div class="erp-field erp-field--grow"><label for="chart-condo">Condomínio</label><select class="erp-select" id="chart-condo"><option>Todos</option><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select></div>
    <div class="erp-field"><label for="chart-from">De</label><input class="erp-input" type="month" id="chart-from" value="2026-06"></div>
    <div class="erp-field"><label for="chart-to">Até</label><input class="erp-input" type="month" id="chart-to" value="2026-11"></div>
</form>
<section class="erp-card" aria-labelledby="chart-title">
    <div class="erp-card__head"><div><h2 id="chart-title"><?= $this->e($currentPage['name']) ?></h2><p>Junho a novembro de 2026</p></div><?php if ($chart): ?><span class="erp-demo-flag">Demonstração</span><?php endif; ?></div>
    <?php if ($chart): ?>
        <?php $this->insert('components/charts/bars', ['chart' => $chart, 'chartCaption' => $currentPage['name']]); ?>
    <?php else: ?>
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'chart', 'emptyTitle' => 'Sem dados para o período', 'emptyText' => 'O gráfico será montado com os dados reais quando o módulo estiver integrado.']); ?>
    <?php endif; ?>
</section>
