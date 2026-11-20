<?php
/** Confirmação de cadastro. Variáveis: $first_name, $confirm_link. */
$this->layout('default', ['subject' => 'Confirme seu cadastro', 'preheader' => 'Falta só um passo para ativar sua conta.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 20px;">Seu cadastro foi criado. Para ativar a conta, confirme o seu e-mail:</p>
<?php $this->insert('partials/button', ['label' => 'Confirmar e-mail', 'href' => $confirm_link]); ?>
<?php $this->insert('partials/link-fallback', ['href' => $confirm_link]); ?>
<?php $this->insert('partials/note', ['text' => 'Se você não fez este cadastro, ignore esta mensagem.']); ?>
