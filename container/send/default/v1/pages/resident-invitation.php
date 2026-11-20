<?php
/** Convite de morador (ADR 0090). Variáveis: $first_name, $condominium, $invitation_link, $expires_days. */
$this->layout('default', ['subject' => 'Convite para o portal do condomínio', 'preheader' => 'Crie seu acesso ao portal do condomínio.', 'heading' => 'Você foi convidado para o portal do condomínio']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 20px;">A administração do <?= $this->e($condominium) ?> convidou você para criar seu acesso ao portal do condomínio. Para aceitar, use o botão abaixo:</p>
<?php $this->insert('partials/button', ['label' => 'Aceitar o convite', 'href' => $invitation_link]); ?>
<?php $this->insert('partials/link-fallback', ['href' => $invitation_link]); ?>
<?php $this->insert('partials/note', ['text' => "O convite vale por {$expires_days} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore esta mensagem."]); ?>
