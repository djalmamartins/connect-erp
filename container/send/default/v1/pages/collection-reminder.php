<?php
/** Aviso de cobrança (AP-137, ADR 0059). Variáveis: $first_name, $unit_label, $entries, $amount, $oldest_due_on, $as_of, $condominium. */
$this->layout('default', ['subject' => 'Aviso de débitos em aberto', 'preheader' => 'Há contas vencidas da sua unidade.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">Até <?= $this->e($as_of) ?>, a unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?> tem <strong><?= $this->e($entries) ?> conta(s) vencida(s)</strong>, somando <strong><?= $this->e($amount) ?></strong>, sem os encargos. A mais antiga venceu em <?= $this->e($oldest_due_on) ?>.</p>
<p style="margin:0 0 16px;">Se já pagou, desconsidere este aviso. Para a segunda via ou um acordo, fale com a administração.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.</p>
