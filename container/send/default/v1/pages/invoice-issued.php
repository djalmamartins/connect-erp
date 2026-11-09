<?php
/** Fatura emitida (ADR 0056). Variáveis: $first_name, $type_name, $competence, $due_on, $amount, $unit_label, $condominium. */
$this->layout('default', ['subject' => 'Nova cobrança do condomínio', 'preheader' => 'A cobrança do mês da sua unidade foi emitida.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">Foi emitida a cobrança <strong><?= $this->e($type_name) ?></strong> da competência <?= $this->e($competence) ?> para a unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?>.</p>
<p style="margin:0 0 16px;">Valor: <strong><?= $this->e($amount) ?></strong>. Vencimento: <strong><?= $this->e($due_on) ?></strong>.</p>
<p style="margin:0 0 16px;">O boleto chega em outro aviso, assim que for registrado no banco.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.</p>
