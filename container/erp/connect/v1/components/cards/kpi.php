<?php
/**
 * Indicador: rótulo, valor, contexto e tendência. Cor reforça o texto, nunca o substitui.
 * @var array{label: string, value: string, meta: string, icon: string, tone: string, trend: ?array} $kpi
 */
$tone = $kpi['tone'] !== '' ? ' erp-kpi--' . $kpi['tone'] : '';
?>
<article class="erp-card erp-kpi<?= $tone ?> erp-enter" style="--erp-delay: <?= (int)($delay ?? 0) ?>ms">
    <div class="erp-kpi__top">
        <span class="erp-kpi__label"><?= $this->e($kpi['label']) ?></span>
        <span class="erp-kpi__icon"><?= $icon($kpi['icon']) ?></span>
    </div>
    <strong class="erp-kpi__value"><?= $this->e($kpi['value']) ?></strong>
    <span class="erp-kpi__meta">
        <?php if (!empty($kpi['trend'])): [$direction, $label] = $kpi['trend']; ?>
            <span class="erp-trend erp-trend--<?= $direction === 'up' ? 'up' : 'down' ?>"><?= $icon($direction === 'up' ? 'trend-up' : 'trend-down', 'erp-icon--sm') ?><?= $this->e($label) ?></span>
        <?php endif; ?>
        <?= $this->e($kpi['meta']) ?>
    </span>
</article>
