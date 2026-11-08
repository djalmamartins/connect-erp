<?php
/**
 * Rosca em SVG com legenda textual (percentuais somam 100).
 * @var list<array{label: string, value: int|float, color: string}> $slices
 */
$total = array_sum(array_column($slices, 'value')) ?: 1;
$radius = 15.915; // circunferência ≈ 100
$offset = 0.0;
?>
<div class="erp-donut">
    <svg viewBox="0 0 36 36" role="img" aria-label="<?= $this->e($donutLabel ?? 'Distribuição') ?>">
        <circle cx="18" cy="18" r="<?= $radius ?>" fill="none" stroke="#eef2f0" stroke-width="5"></circle>
        <?php foreach ($slices as $slice): $share = $slice['value'] / $total * 100; ?>
            <circle cx="18" cy="18" r="<?= $radius ?>" fill="none" stroke="<?= $this->e($slice['color']) ?>" stroke-width="5" stroke-dasharray="<?= round($share, 2) ?> <?= round(100 - $share, 2) ?>" stroke-dashoffset="<?= round(-$offset, 2) ?>"></circle>
        <?php $offset += $share; endforeach; ?>
    </svg>
    <ul>
        <?php foreach ($slices as $slice): ?>
            <li><i style="background: <?= $this->e($slice['color']) ?>"></i><span><?= $this->e($slice['label']) ?></span><strong><?= $this->e(number_format($slice['value'] / $total * 100, 0, ',', '.')) ?>%</strong></li>
        <?php endforeach; ?>
    </ul>
</div>
