<?php
/** Uma conta da unidade: pagar ou ver o recibo (ADR 0302, ajustes de 14/11). @var array $entry ResidentPortal::entry() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
[$label, $tone] = F::badge(F::SITUATIONS, $entry['situation']);
$charge = $entry['charge'];
$payable = in_array($entry['situation'], ['open', 'overdue'], true);
?>
<p><a href="<?= app_url('/boletos') ?>" class="app-back">‹ Boletos</a></p>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?>
    <h1><?= $this->e($entry['description']) ?></h1>
    <p><span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span> Competência <?= F::competence($entry['competence']) ?></p>
</header>
<div class="app-card app-amount">
    <span>Valor</span>
    <strong><?= F::money($entry['amount_cents']) ?></strong>
    <ul class="app-facts">
        <li>Vencimento: <?= F::date($entry['due_on']) ?></li>
        <?php if ($entry['paid_on']): ?><li>Pago em: <?= F::date($entry['paid_on']) ?></li><?php endif; ?>
    </ul>
</div>
<?php if ($entry['receipt'] !== null): ?>
    <div class="app-card app-cta">
        <span class="app-cta__icon app-cta__icon--ok"><svg aria-hidden="true"><use href="#i-check"></use></svg></span>
        <div><strong>Conta paga</strong><p>Quer o recibo? Dá para imprimir ou salvar em PDF.</p></div>
        <a class="app-button" href="<?= app_url('/boletos/' . $entry['entry_id'] . '/recibo') ?>"><svg aria-hidden="true"><use href="#i-print"></use></svg>Ver recibo</a>
    </div>
<?php elseif ($payable && $charge !== null): ?>
    <div class="app-card app-cta">
        <span class="app-cta__icon"><svg aria-hidden="true"><use href="#i-download"></use></svg></span>
        <div><strong><?= $entry['situation'] === 'overdue' ? 'Esta conta está vencida' : 'Esta conta está em aberto' ?></strong><p>Quer baixar o boleto para pagar?</p></div>
        <div class="app-charge__pay">
            <?php if ($charge['pdf_available']): ?>
                <a class="app-button" href="<?= app_url('/boletos/' . $charge['id'] . '/pdf') ?>" target="_blank" rel="noopener"><svg aria-hidden="true"><use href="#i-download"></use></svg>Baixar boleto</a>
            <?php endif; ?>
            <?php if (!empty($charge['digitable_line'])): ?>
                <button type="button" class="app-button app-button--ghost" data-app-copy="<?= $this->e((string)$charge['digitable_line']) ?>"><svg aria-hidden="true"><use href="#i-copy"></use></svg>Copiar linha digitável</button>
            <?php endif; ?>
            <?php if (!empty($charge['pix_copy_paste'])): ?>
                <button type="button" class="app-button app-button--ghost" data-app-copy="<?= $this->e((string)$charge['pix_copy_paste']) ?>"><svg aria-hidden="true"><use href="#i-copy"></use></svg>Copiar Pix</button>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($payable): ?>
    <div class="app-card"><p class="app-muted">O boleto desta conta ainda não foi emitido. Em caso de dúvida, fale com a administração do condomínio.</p></div>
<?php else: ?>
    <div class="app-card"><p class="app-muted">Esta conta foi renegociada num acordo. As parcelas do acordo aparecem em Boletos.</p></div>
<?php endif; ?>
