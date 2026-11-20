<?php
/** Boleto registrado (ADR 0056). Variáveis: $first_name, $description, $due_on, $amount, $digitable_line, $unit_label, $condominium. */
$this->layout('default', ['subject' => 'Seu boleto do condomínio', 'preheader' => 'O boleto da sua unidade está registrado no banco.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">O boleto de <strong><?= $this->e($description) ?></strong>, da unidade <?= $this->e($unit_label) ?> do <?= $this->e($condominium) ?>, está registrado no banco.</p>
<?php $this->insert('partials/box', ['rows' => [['Valor', $amount], ['Vencimento', $due_on]]]); ?>
<?php if (($digitable_line ?? '') !== ''): ?>
<p style="margin:0 0 8px;">Linha digitável para pagar no aplicativo do seu banco:</p>
<?php $this->insert('partials/box', ['rows' => [['Linha digitável', $digitable_line]], 'mono' => true]); ?>
<?php endif; ?>
<p style="margin:0 0 16px;">Confira sempre o nome do beneficiário antes de pagar. A administração não pede pagamento por mensagem de outro endereço.</p>
<?php $this->insert('partials/note', ['text' => 'Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.']); ?>
