<?php
/** Boletos e extrato da unidade (ADR 0302). @var array $statement ResidentPortal::charges() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$t = $statement['totals'];
?>
<header class="app-page-head">
    <h1>Boletos</h1>
    <p>Contas da unidade <?= $this->e($statement['unit_label']) ?> de <?= F::date($statement['from']) ?> a <?= F::date($statement['to']) ?>.</p>
</header>
<div class="app-grid app-grid--3">
    <div class="app-card app-stat app-stat--danger"><span class="app-stat__label">Vencido</span><strong class="app-stat__value"><?= F::money($t['overdue_cents']) ?></strong></div>
    <div class="app-card app-stat"><span class="app-stat__label">A vencer</span><strong class="app-stat__value"><?= F::money($t['open_cents']) ?></strong></div>
    <div class="app-card app-stat app-stat--success"><span class="app-stat__label">Pago no período</span><strong class="app-stat__value"><?= F::money($t['paid_cents']) ?></strong></div>
</div>

<form class="app-card app-filter" method="get" action="<?= app_url('/boletos') ?>">
    <label>De <input type="date" name="de" value="<?= $this->e($statement['from']) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= $this->e($statement['to']) ?>"></label>
    <button class="app-button app-button--ghost" type="submit">Ver período</button>
</form>

<?php if ($statement['lines'] === []): ?>
    <div class="app-card app-empty"><p>Nenhuma conta neste período.</p></div>
<?php endif; ?>
<ul class="app-list">
<?php foreach ($statement['lines'] as $line): [$label, $tone] = F::badge(F::SITUATIONS, $line['situation']); $charge = $line['charge']; ?>
    <li class="app-card app-charge">
        <div class="app-charge__head">
            <span><strong><?= $this->e($line['description']) ?></strong><small>Vence em <?= F::date($line['due_on']) ?><?= $line['paid_on'] ? ' · pago em ' . F::date($line['paid_on']) : '' ?></small></span>
            <span class="app-charge__amount"><?= F::money($line['amount_cents']) ?><span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span></span>
        </div>
        <?php if ($charge !== null && in_array($line['situation'], ['open', 'overdue'], true)): ?>
            <div class="app-charge__pay">
                <?php if (!empty($charge['digitable_line'])): ?>
                    <button type="button" class="app-button app-button--ghost" data-app-copy="<?= $this->e((string)$charge['digitable_line']) ?>"><svg aria-hidden="true"><use href="#i-copy"></use></svg>Copiar linha digitável</button>
                <?php endif; ?>
                <?php if (!empty($charge['pix_copy_paste'])): ?>
                    <button type="button" class="app-button app-button--ghost" data-app-copy="<?= $this->e((string)$charge['pix_copy_paste']) ?>"><svg aria-hidden="true"><use href="#i-copy"></use></svg>Copiar Pix</button>
                <?php endif; ?>
                <?php if ($charge['pdf_available']): ?>
                    <a class="app-button" href="<?= app_url('/boletos/' . $charge['id'] . '/pdf') ?>" target="_blank" rel="noopener"><svg aria-hidden="true"><use href="#i-download"></use></svg>Abrir boleto</a>
                <?php endif; ?>
            </div>
        <?php elseif (in_array($line['situation'], ['open', 'overdue'], true)): ?>
            <p class="app-muted app-charge__note">O boleto desta conta ainda não foi emitido. Em caso de dúvida, fale com a administração.</p>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
