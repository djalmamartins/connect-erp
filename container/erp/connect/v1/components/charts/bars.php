<?php
/**
 * Gráfico de barras agrupadas em CSS (até 2 séries). Inclui tabela acessível equivalente.
 * @var array{labels: list<string>, series: list<array{name: string, values: list<int|float>}>, unit: string} $chart
 * @var string $chartId
 */
$max = 0;
foreach ($chart['series'] as $series) {
    $max = max($max, ...$series['values']);
}
$max = $max > 0 ? $max : 1;
?>
<div class="erp-chart">
    <div class="erp-chart__legend" aria-hidden="true">
        <?php foreach ($chart['series'] as $i => $series): ?><span><i class="<?= $i ? 'is-b' : '' ?>"></i><?= $this->e($series['name']) ?></span><?php endforeach; ?>
        <span>Valores em <?= $this->e($chart['unit']) ?></span>
    </div>
    <div class="erp-bars" aria-hidden="true">
        <?php foreach ($chart['labels'] as $index => $label): ?>
            <div class="erp-bars__group">
                <?php foreach ($chart['series'] as $i => $series): $value = $series['values'][$index] ?? 0; ?>
                    <span class="erp-bars__bar<?= $i ? ' is-b' : '' ?>" style="height: <?= round($value / $max * 100, 1) ?>%" title="<?= $this->e($series['name'] . ' · ' . $label . ': ' . number_format((float)$value, 0, ',', '.')) ?>"></span>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="erp-bars__labels" aria-hidden="true"><?php foreach ($chart['labels'] as $label): ?><span><?= $this->e($label) ?></span><?php endforeach; ?></div>
    <table class="erp-sr-only">
        <caption><?= $this->e($chartCaption ?? 'Dados do gráfico') ?> (<?= $this->e($chart['unit']) ?>)</caption>
        <thead><tr><th scope="col">Mês</th><?php foreach ($chart['series'] as $series): ?><th scope="col"><?= $this->e($series['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($chart['labels'] as $index => $label): ?><tr><th scope="row"><?= $this->e($label) ?></th><?php foreach ($chart['series'] as $series): ?><td><?= $this->e(number_format((float)($series['values'][$index] ?? 0), 0, ',', '.')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table>
</div>
