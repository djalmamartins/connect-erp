<?php
/** Recuperação de senha. Variáveis: $first_name, $forget_link. */
$this->layout('default', ['subject' => 'Recuperação de senha', 'preheader' => 'Use o link para criar uma nova senha.', 'heading' => 'Crie uma nova senha']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 20px;">Recebemos um pedido para redefinir a senha da sua conta. Para criar uma nova senha, use o botão abaixo:</p>
<?php $this->insert('partials/button', ['label' => 'Criar nova senha', 'href' => $forget_link]); ?>
<?php $this->insert('partials/link-fallback', ['href' => $forget_link]); ?>
<?php $this->insert('partials/note', ['text' => 'Se você não pediu a troca de senha, ignore esta mensagem. Sua senha atual continua valendo.']); ?>
