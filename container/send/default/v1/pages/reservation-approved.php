<?php
/** Reserva aprovada (ADR 0056). Variáveis: $first_name, $area, $date, $starts, $ends, $fee (opcional), $condominium. */
$this->layout('default', ['subject' => 'Reserva aprovada', 'preheader' => 'A sua reserva da área comum foi aprovada.']);
$period = trim((string)($starts ?? '')) !== '' ? ' das ' . $starts . ' às ' . ($ends ?? '') : '';
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">A reserva de <strong><?= $this->e($area) ?></strong> em <strong><?= $this->e($date) ?></strong><?= $this->e($period) ?> no <?= $this->e($condominium) ?> foi aprovada.</p>
<?php if (($fee ?? '') !== ''): ?>
<p style="margin:0 0 16px;">Taxa da reserva: <strong><?= $this->e($fee) ?></strong>.</p>
<?php endif; ?>
<p style="margin:0 0 16px;">Siga as regras de uso da área. Para cancelar, avise a administração com antecedência.</p>
<p style="margin:0;font-size:13px;color:#5b6878;">Você recebe este aviso porque a reserva foi feita para a sua unidade.</p>
