<?php
/** Acordo e parcelas (ADR 0056). Variáveis: $first_name, $number, $installments, $installment, $total, $first_due_on, $last_due_on, $unit_label, $condominium. */
$this->layout('default', ['subject' => 'Seu acordo e as parcelas', 'preheader' => 'O acordo da sua unidade foi registrado.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">O acordo nº <?= $this->e($number) ?> da unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?> foi registrado.</p>
<p style="margin:0 0 16px;">Total: <strong><?= $this->e($total) ?></strong>, em <strong><?= $this->e($installments) ?> parcela(s)</strong>; a primeira de <?= $this->e($installment) ?>.
Primeira parcela em <strong><?= $this->e($first_due_on) ?></strong> e última em <?= $this->e($last_due_on) ?>.</p>
<p style="margin:0 0 16px;">Cada parcela tem o seu boleto, enviado em aviso próprio. Parcelas em atraso podem levar à quebra do acordo, conforme a regra do condomínio.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.</p>
