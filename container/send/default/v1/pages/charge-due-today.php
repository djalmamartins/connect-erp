<?php
/**
 * Boleto que vence hoje (ADR 0600). Um e-mail por unidade no dia do vencimento.
 * Variáveis: $first_name, $unit_label, $condominium, $items, $amount, $due_on, $description, $digitable_line, $lines, $app_link.
 */
$single = (int)($items ?? 1) === 1;
$this->layout('default', ['subject' => 'Seu boleto do condomínio vence hoje', 'preheader' => 'O boleto da unidade ' . ($unit_label ?? '') . ' vence hoje.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<?php if ($single): ?>
<p style="margin:0 0 16px;">O boleto de <strong><?= $this->e($description ?? '') ?></strong>, da unidade <?= $this->e($unit_label ?? '') ?> do <?= $this->e($condominium) ?>, <strong>vence hoje</strong>.</p>
<?php $this->insert('partials/box', ['rows' => [['Valor', $amount ?? ''], ['Vencimento', $due_on ?? '']]]); ?>
<?php if (($digitable_line ?? '') !== ''): ?>
<p style="margin:0 0 8px;">Linha digitável para pagar no aplicativo do seu banco:</p>
<?php $this->insert('partials/box', ['rows' => [['Linha digitável', $digitable_line]], 'mono' => true]); ?>
<?php endif; ?>
<?php else: ?>
<p style="margin:0 0 16px;">A unidade <?= $this->e($unit_label ?? '') ?> do <?= $this->e($condominium) ?> tem <strong><?= $this->e($items) ?> boletos que vencem hoje</strong>, somando <strong><?= $this->e($amount ?? '') ?></strong>:</p>
<?php $this->insert('partials/box', ['rows' => array_map(static fn (string $l): array => ['Boleto', $l], array_filter(explode("\n", (string)($lines ?? ''))))]); ?>
<?php endif; ?>
<?php if (($app_link ?? '') !== ''): ?>
<?php $this->insert('partials/button', ['label' => 'Ver o boleto no app', 'href' => $app_link]); ?>
<?php endif; ?>
<p style="margin:0 0 16px;">Se já pagou, desconsidere este aviso. Confira sempre o nome do beneficiário antes de pagar.</p>
<?php $this->insert('partials/note', ['text' => 'Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.']); ?>
