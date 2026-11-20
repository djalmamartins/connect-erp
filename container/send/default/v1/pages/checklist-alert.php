<?php
/** Aviso de vencimento do checklist de chegada. Variáveis: $first_name, $message, $protocol, $link. */
$this->layout('default', ['subject' => 'Checklist de chegada', 'preheader' => $message]);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;"><?= $this->e($message) ?></p>
<?php if (!empty($protocol)): ?>
    <?php $this->insert('partials/box', ['rows' => [['Protocolo', $protocol]]]); ?>
<?php endif; ?>
<?php $this->insert('partials/button', ['label' => 'Abrir o checklist', 'href' => $link]); ?>
<?php $this->insert('partials/note', ['text' => 'Você recebe este aviso por ser o responsável pelo item ou por gerenciar este condomínio. Ao atualizar o item, o aviso deixa de se repetir.']); ?>
