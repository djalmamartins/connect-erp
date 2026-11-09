<?php
/** Boleto registrado (ADR 0056). Variáveis: $first_name, $description, $due_on, $amount, $digitable_line, $unit_label, $condominium. */
$this->layout('default', ['subject' => 'Seu boleto do condomínio', 'preheader' => 'O boleto da sua unidade está registrado no banco.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">O boleto de <strong><?= $this->e($description) ?></strong>, da unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?>, está registrado no banco.</p>
<p style="margin:0 0 16px;">Valor: <strong><?= $this->e($amount) ?></strong>. Vencimento: <strong><?= $this->e($due_on) ?></strong>.</p>
<?php if (($digitable_line ?? '') !== ''): ?>
<p style="margin:0 0 8px;">Linha digitável para pagar no aplicativo do seu banco:</p>
<p style="margin:0 0 16px;font-family:monospace;font-size:15px;word-break:break-all;"><?= $this->e($digitable_line) ?></p>
<?php endif; ?>
<p style="margin:0 0 16px;">Confira sempre o nome do beneficiário antes de pagar. A administração não pede pagamento por mensagem de outro endereço.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.</p>
