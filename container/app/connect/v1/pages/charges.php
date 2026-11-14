<?php
/** Boletos e extrato da unidade (ADR 0302). @var array $statement ResidentPortal::charges() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$t = $statement['totals'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?>
    <h1>Boletos</h1>
    <p>Contas da unidade <?= $this->e($statement['unit_label']) ?>. Role para ver as mais antigas.</p>
</header>
<div class="app-grid app-grid--3">
    <div class="app-card app-stat app-stat--danger"><span class="app-stat__label">Vencido</span><strong class="app-stat__value"><?= F::money($t['overdue_cents']) ?></strong></div>
    <div class="app-card app-stat"><span class="app-stat__label">A vencer</span><strong class="app-stat__value"><?= F::money($t['open_cents']) ?></strong></div>
    <div class="app-card app-stat app-stat--success"><span class="app-stat__label">Pago em 12 meses</span><strong class="app-stat__value"><?= F::money($t['paid_cents']) ?></strong></div>
</div>

<?php if ($statement['lines'] === [] && $more === null): ?>
    <?php $this->insert('partials/empty', ['icon' => 'receipt', 'text' => 'Nenhuma conta por aqui ainda.']) ?>
<?php endif; ?>
<ul class="app-items" data-app-feed>
<?php $this->insert('partials/charge-lines', ['lines' => $statement['lines'], 'more' => $more]) ?>
</ul>
<noscript><?php if ($more !== null): ?><p><a class="app-button app-button--ghost" href="<?= $this->e(str_replace('parcial=1&', '', $more)) ?>">Ver contas mais antigas</a></p><?php endif; ?></noscript>

