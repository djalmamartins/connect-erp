<?php
/** Linhas dos boletos (página e rolagem infinita). @var list<array> $lines @var ?string $more endereço da próxima janela */
use Source\Controllers\App\Resident\Format as F;
?>
<?php foreach ($lines as $line): [$label, $tone] = F::badge(F::SITUATIONS, $line['situation']); $charge = $line['charge']; ?>
    <li class="app-card app-charge">
        <a class="app-charge__head app-charge__link" href="<?= app_url('/boletos/' . $line['entry_id']) ?>" title="<?= $line['situation'] === 'paid' ? 'Ver recibo' : 'Ver a conta' ?>">
            <span class="app-item__icon app-item__icon--<?= $line['situation'] === 'overdue' ? 'danger' : ($line['situation'] === 'paid' ? 'success' : 'brand') ?>" aria-hidden="true"><svg><use href="#i-receipt"></use></svg></span>
            <span class="app-item__text"><strong><?= $this->e($line['description']) ?></strong><small>Vence em <?= F::date($line['due_on']) ?><?= $line['paid_on'] ? ' · pago em ' . F::date($line['paid_on']) : '' ?></small></span>
            <span class="app-charge__amount"><?= F::money($line['amount_cents']) ?><span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span></span>
            <svg class="app-charge__chevron" aria-hidden="true"><use href="#i-chevron"></use></svg>
        </a>
        <?php if ($line['situation'] === 'paid'): ?>
            <a class="app-charge__receipt" href="<?= app_url('/boletos/' . $line['entry_id'] . '/recibo') ?>"><svg aria-hidden="true"><use href="#i-print"></use></svg>Recibo</a>
        <?php endif; ?>
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
<?php if ($more !== null): ?><li class="app-feed-more" data-app-more="<?= $this->e($more) ?>"><span class="app-spinner" aria-hidden="true"></span>Carregando contas mais antigas…</li><?php endif; ?>
