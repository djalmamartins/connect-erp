<?php
/** Aviso de vencimento do checklist de chegada. Variáveis: $first_name, $message, $protocol, $link. */
$this->layout('default', ['subject' => 'Checklist de chegada', 'preheader' => $message]);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;"><?= $this->e($message) ?></p>
<?php if (!empty($protocol)): ?>
    <p style="margin:0 0 16px;">Protocolo: <strong><?= $this->e($protocol) ?></strong></p>
<?php endif; ?>
<p style="margin:0 0 24px;">
    <a href="<?= $this->e($link) ?>" style="display:inline-block;background:#0f5fa8;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">Abrir o checklist</a>
</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso por ser o responsável pelo item ou por gerenciar este condomínio. Ao atualizar o item, o aviso deixa de se repetir.</p>
