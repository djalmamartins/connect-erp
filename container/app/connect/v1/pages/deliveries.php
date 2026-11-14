<?php
/** Encomendas da unidade na portaria (ADR 0302). @var list<array> $deliveries */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Encomendas</h1><p>O que chegou na portaria para a sua unidade.</p></header>
<?php if ($deliveries === []): ?><?php $this->insert('partials/empty', ['icon' => 'box', 'text' => 'Nenhuma encomenda nos últimos 90 dias.']) ?><?php endif; ?>
<ul class="app-items">
<?php foreach ($deliveries as $d): [$label, $tone] = F::badge(F::DELIVERY, $d['status']);
    $this->insert('partials/item', ['icon' => 'box', 'tone' => $d['status'] === 'active' ? 'warning' : 'neutral',
        'title' => (F::CARRIERS[$d['carrier']] ?? $d['carrier']) . ($d['tracking_code'] ? ' · ' . $d['tracking_code'] : ''),
        'lines' => ['Para ' . $d['recipient'] . ' · chegou em ' . F::date($d['received_on']) . ($d['received_time'] ? ' às ' . $d['received_time'] : ''),
            $d['picked_up_on'] ? 'Retirada em ' . F::date($d['picked_up_on']) . ($d['picked_up_by'] ? ' por ' . $d['picked_up_by'] : '') : ''],
        'badge' => [$label, $tone]]);
endforeach; ?>
</ul>
