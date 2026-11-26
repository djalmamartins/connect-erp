<?php
/**
 * Rosca em SVG com legenda textual (percentuais somam 100).
 *
 * 26/11/2026 (ADR 0654): o anel antigo (traço 5 num raio de 15,9, dentro de um viewBox 36×36)
 * passava da borda do desenho e saía cortado, grosso e achatado. Agora o anel é fino e cabe
 * inteiro no viewBox, as fatias têm um respiro entre si, o giro fica no próprio SVG (o texto do
 * centro não gira junto) e o centro pode mostrar um destaque ($donutCenter + $donutCenterCaption).
 *
 * @var list<array{label: string, value: int|float, color: string}> $slices
 * @var string|null $donutLabel
 * @var string|null $donutCenter
 * @var string|null $donutCenterCaption
 */
$total = array_sum(array_column($slices, 'value')) ?: 1;
$radius = 15.915; // circunferência ≈ 100, então cada fatia usa o próprio percentual como traço
$stroke = 3.6;
$visible = array_values(array_filter($slices, static fn (array $s): bool => $s['value'] > 0));
$gap = count($visible) > 1 ? 0.8 : 0.0; // respiro entre as fatias, em pontos percentuais
$offset = 0.0;
?>
<div class="erp-donut">
    <div class="erp-donut__chart">
        <svg viewBox="0 0 42 42" role="img" aria-label="<?= $this->e($donutLabel ?? 'Distribuição') ?>">
            <circle cx="21" cy="21" r="<?= $radius ?>" fill="none" class="erp-donut__track" stroke-width="<?= $stroke ?>"></circle>
            <g transform="rotate(-90 21 21)">
                <?php foreach ($visible as $slice): $share = $slice['value'] / $total * 100; $dash = max($share - $gap, 0.4); ?>
                    <circle cx="21" cy="21" r="<?= $radius ?>" fill="none" stroke="<?= $this->e($slice['color']) ?>" stroke-width="<?= $stroke ?>" stroke-dasharray="<?= round($dash, 2) ?> <?= round(100 - $dash, 2) ?>" stroke-dashoffset="<?= round(-($offset + $gap / 2), 2) ?>"></circle>
                <?php $offset += $share; endforeach; ?>
            </g>
        </svg>
        <?php if (($donutCenter ?? '') !== ''): ?>
            <div class="erp-donut__center" aria-hidden="true"><strong><?= $this->e($donutCenter) ?></strong><?php if (($donutCenterCaption ?? '') !== ''): ?><small><?= $this->e($donutCenterCaption) ?></small><?php endif; ?></div>
        <?php endif; ?>
    </div>
    <ul>
        <?php foreach ($slices as $slice): ?>
            <li><i style="background: <?= $this->e($slice['color']) ?>"></i><span><?= $this->e($slice['label']) ?></span><strong><?= $this->e(number_format($slice['value'] / $total * 100, 0, ',', '.')) ?>%</strong></li>
        <?php endforeach; ?>
    </ul>
</div>
