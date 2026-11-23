<?php
/**
 * Quadro de destaque (ADR 0397): protocolo, valores, linha digitável.
 * Variáveis: $rows (lista de [rótulo, valor]), $mono (opcional, valor em fonte monoespaçada).
 */
?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;background:#f6faf8;border-radius:8px;border-collapse:separate;">
    <?php foreach ($rows as [$label, $value]): ?>
        <tr>
            <td style="padding:10px 16px 0;font-size:11px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:#6f6a5e;"><?= $this->e($label) ?></td>
        </tr>
        <tr>
            <?php if (!empty($mono)): ?>
            <td style="padding:2px 16px 10px;font-size:14px;font-weight:700;color:#1f1d17;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;"><?= $this->e($value) ?></td>
            <?php else: ?>
            <td style="padding:2px 16px 10px;font-size:16px;font-weight:700;color:#1f1d17;"><?= $this->e($value) ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
</table>
