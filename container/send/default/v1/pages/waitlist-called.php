<?php
/** Chamada da lista de espera (ADR 0055). Variáveis: $first_name, $area, $date, $condominium. */
$this->layout('default', ['subject' => 'Sua vez na lista de espera', 'preheader' => 'A data que você queria ficou livre.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">A reserva de <strong><?= $this->e($area) ?></strong> em <strong><?= $this->e($date) ?></strong> no <?= $this->e($condominium) ?> foi cancelada, e você é o próximo da lista de espera.</p>
<p style="margin:0 0 16px;">Para garantir a data, faça a reserva com a administração ou pelo portal. A data só fica sua depois da reserva.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque se inscreveu na lista de espera desta área.</p>
