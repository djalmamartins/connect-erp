<?php
/**
 * Aviso genérico do sistema na moldura Connect (ADR 0397): Help Desk, agenda, comunicação interna,
 * propostas e teste de e-mail. Todo texto é escapado aqui; quem chama passa só texto puro.
 *
 * Variáveis: $subject, $preheader (opcional), $heading (opcional), $first_name (opcional),
 * $paragraphs (lista de textos), $quote (opcional, texto com quebras de linha), $quote_author (opcional),
 * $facts (opcional, lista de [rótulo, valor]), $highlight (opcional, lista de [rótulo, valor] em quadro),
 * $button_label + $button_link (opcional), $note (opcional), $footer_note (opcional).
 */
$this->layout('default', [
    'subject' => $subject ?? '',
    'preheader' => $preheader ?? '',
    'heading' => $heading ?? ($subject ?? ''),
    'footer_note' => $footer_note ?? null,
]);
?>
<?php if (($first_name ?? '') !== ''): ?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<?php else: ?>
<p style="margin:0 0 16px;">Olá.</p>
<?php endif; ?>
<?php foreach (($paragraphs ?? []) as $paragraph): ?>
<p style="margin:0 0 16px;"><?= nl2br($this->e($paragraph)) ?></p>
<?php endforeach; ?>
<?php if (($quote ?? '') !== ''): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;">
    <tr>
        <td style="padding:14px 16px;background:#fbf8f0;border-left:4px solid #c5a131;border-radius:0 8px 8px 0;font-size:15px;line-height:1.6;color:#3a3730;">
            <?= nl2br($this->e($quote)) ?>
            <?php if (($quote_author ?? '') !== ''): ?><br><span style="font-size:13px;color:#6f6a5e;">— <?= $this->e($quote_author) ?></span><?php endif; ?>
        </td>
    </tr>
</table>
<?php endif; ?>
<?php if (!empty($facts)): ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-collapse:collapse;font-size:14px;">
    <?php foreach ($facts as [$label, $value]): ?>
    <tr>
        <td style="padding:8px 0;border-bottom:1px solid #f0eadb;color:#6f6a5e;width:40%;"><?= $this->e($label) ?></td>
        <td style="padding:8px 0;border-bottom:1px solid #f0eadb;font-weight:600;"><?= $this->e($value) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php if (!empty($highlight)): ?>
<?php $this->insert('partials/box', ['rows' => $highlight]); ?>
<?php endif; ?>
<?php if (($button_link ?? '') !== '' && ($button_label ?? '') !== ''): ?>
<?php $this->insert('partials/button', ['label' => $button_label, 'href' => $button_link]); ?>
<?php endif; ?>
<?php if (($note ?? '') !== ''): ?>
<?php $this->insert('partials/note', ['text' => $note]); ?>
<?php endif; ?>
