<?php
/** Aviso de mudança no 2FA (ADR 0028). Variáveis: $first_name, $change, $when. */
$this->layout('default', ['subject' => 'Autenticação em dois fatores alterada', 'preheader' => 'Houve uma mudança na segurança da sua conta.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">A autenticação em dois fatores da sua conta foi <strong><?= $this->e($change) ?></strong> em <?= $this->e($when) ?>.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Se não foi você ou alguém da administração a seu pedido, troque sua senha e avise o administrador do sistema imediatamente.</p>
