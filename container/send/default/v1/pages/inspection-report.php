<?php
/**
 * Relatório de vistoria (o PDF completo vai anexado).
 * Variáveis: $condominium, $template, $date, $inspector, $counts, $issues, $message, $sender.
 */
$this->layout('default', ['subject' => 'Relatório de vistoria', 'preheader' => "Vistoria de {$condominium} em {$date}."]);
?>
<p style="margin:0 0 16px;">Olá.</p>
<p style="margin:0 0 16px;">Segue o relatório da vistoria presencial <strong><?= $this->e($template) ?></strong> no condomínio <strong><?= $this->e($condominium) ?></strong>, feita em <?= $this->e($date) ?> por <?= $this->e($inspector) ?>. O relatório completo, com as fotos, está no PDF anexo.</p>
<?php if ($message !== ''): ?>
    <p style="margin:0 0 16px;padding:12px 14px;background:#fbf8f0;border-left:3px solid #c5a131;"><?= nl2br($this->e($message)) ?><br><span style="font-size:13px;color:#6f6a5e;">— <?= $this->e($sender) ?></span></p>
<?php endif; ?>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 16px;border-collapse:collapse;font-size:14px;">
    <tr>
        <td style="padding:8px;border:1px solid #efe6cc;">Conformes</td><td style="padding:8px;border:1px solid #efe6cc;text-align:right;"><strong><?= (int)$counts['ok'] ?></strong></td>
    </tr>
    <tr>
        <td style="padding:8px;border:1px solid #efe6cc;">Com problema</td><td style="padding:8px;border:1px solid #efe6cc;text-align:right;color:#b42318;"><strong><?= (int)$counts['issue'] ?></strong></td>
    </tr>
    <tr>
        <td style="padding:8px;border:1px solid #efe6cc;">Não se aplica</td><td style="padding:8px;border:1px solid #efe6cc;text-align:right;"><strong><?= (int)$counts['not_applicable'] ?></strong></td>
    </tr>
</table>
<?php if ($issues !== []): ?>
    <p style="margin:0 0 8px;font-weight:bold;">Problemas encontrados</p>
    <ul style="margin:0 0 16px;padding-left:20px;">
        <?php foreach ($issues as $issue): ?>
            <li style="margin:0 0 6px;"><strong><?= $this->e($issue['label']) ?></strong> (<?= $this->e($issue['section']) ?>): <?= $this->e((string)$issue['note']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php $this->insert('partials/note', ['text' => 'Dúvidas sobre a vistoria podem ser tratadas diretamente com a Connect.']); ?>
