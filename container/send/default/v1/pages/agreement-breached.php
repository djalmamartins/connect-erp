<?php
/** Quebra de acordo (ADR 0056). Variáveis: $first_name, $number, $total, $overdue_installments, $unit_label, $condominium. O motivo interno não vai no aviso. */
$this->layout('default', ['subject' => 'Acordo encerrado por falta de pagamento', 'preheader' => 'O acordo da sua unidade foi encerrado.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">O acordo nº <?= $this->e($number) ?> da unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?> foi encerrado por atraso no pagamento das parcelas, conforme a regra do condomínio.</p>
<p style="margin:0 0 16px;">Parcelas em atraso: <strong><?= $this->e($overdue_installments) ?></strong>. Valor total do acordo: <?= $this->e($total) ?>.</p>
<p style="margin:0 0 16px;">Para conhecer o saldo atualizado e as opções de regularização, fale com a administração.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.</p>
