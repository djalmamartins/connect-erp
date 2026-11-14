<?php
/** Encomendas da unidade na portaria (ADR 0302). @var list<array> $deliveries */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Encomendas</h1><p>O que chegou na portaria para a sua unidade.</p></header>
<?php if ($deliveries === []): ?><div class="app-card app-empty"><p>Nenhuma encomenda nos últimos 90 dias.</p></div><?php endif; ?>
<ul class="app-list">
<?php foreach ($deliveries as $d): [$label, $tone] = F::badge(F::DELIVERY, $d['status']); ?>
    <li class="app-card app-row">
        <span><strong><?= $this->e($d['recipient']) ?></strong>
            <small><?= $this->e(F::CARRIERS[$d['carrier']] ?? $d['carrier']) ?><?= $d['tracking_code'] ? ' · ' . $this->e($d['tracking_code']) : '' ?> · chegou em <?= F::date($d['received_on']) ?><?= $d['received_time'] ? ' às ' . $d['received_time'] : '' ?>
            <?= $d['picked_up_on'] ? ' · retirada em ' . F::date($d['picked_up_on']) . ($d['picked_up_by'] ? ' por ' . $this->e($d['picked_up_by']) : '') : '' ?></small></span>
        <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
    </li>
<?php endforeach; ?>
</ul>
