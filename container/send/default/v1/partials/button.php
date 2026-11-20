<?php
/** Botão do e-mail (layout Connect, ADR 0397). Variáveis: $label, $href. Tabela para funcionar também no Outlook. */
?>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:4px 0 24px;">
    <tr>
        <td style="background:#07884c;border-radius:8px;">
            <a href="<?= $this->e($href) ?>" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;"><?= $this->e($label) ?></a>
        </td>
    </tr>
</table>
