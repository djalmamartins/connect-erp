<?php
/**
 * Boleto em atraso (ADR 0601): sai com 10, 15, 30, 45 e 60 dias de atraso, uma vez em cada marco.
 * Variáveis: $first_name, $unit_label, $condominium, $items, $amount, $due_on, $description, $days_late, $lines, $app_link.
 */
$single = (int)($items ?? 1) === 1;
$this->layout('default', ['subject' => 'Boleto do condomínio em atraso', 'preheader' => 'Há boleto vencido da unidade ' . ($unit_label ?? '') . '.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<?php if ($single): ?>
<p style="margin:0 0 16px;">O boleto de <strong><?= $this->e($description ?? '') ?></strong>, da unidade <?= $this->e($unit_label ?? '') ?> do <?= $this->e($condominium) ?>, venceu há <strong><?= $this->e($days_late ?? '') ?> dias</strong> e ainda não consta como pago.</p>
<?php $this->insert('partials/box', ['rows' => [['Valor sem encargos', $amount ?? ''], ['Vencimento', $due_on ?? '']]]); ?>
<?php else: ?>
<p style="margin:0 0 16px;">A unidade <?= $this->e($unit_label ?? '') ?> do <?= $this->e($condominium) ?> tem <strong><?= $this->e($items) ?> boletos em atraso</strong>, somando <strong><?= $this->e($amount ?? '') ?></strong> sem os encargos:</p>
<?php $this->insert('partials/box', ['rows' => array_map(static fn (string $l): array => ['Boleto', $l], array_filter(explode("\n", (string)($lines ?? ''))))]); ?>
<?php endif; ?>
<?php if (($app_link ?? '') !== ''): ?>
<?php $this->insert('partials/button', ['label' => 'Ver no app', 'href' => $app_link]); ?>
<?php endif; ?>
<p style="margin:0 0 16px;">Se já pagou, desconsidere este aviso. Para a segunda via ou um acordo, fale com a administração.</p>
<?php $this->insert('partials/note', ['text' => 'Você recebe este aviso porque autorizou receber a cobrança por e-mail. Para deixar de receber, fale com a administração.']); ?>
