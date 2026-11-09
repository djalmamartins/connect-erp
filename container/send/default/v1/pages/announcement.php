<?php
/** Comunicado geral (AP-111, ADR 0059). Variáveis: $first_name, $subject, $body, $condominium. O texto é escapado; quebras de linha viram <br>. */
$this->layout('default', ['subject' => $subject ?? 'Comunicado do condomínio', 'preheader' => 'Comunicado da administração do condomínio.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 8px;font-weight:600;"><?= $this->e($subject ?? '') ?></p>
<p style="margin:0 0 16px;"><?= nl2br($this->e($body ?? '')) ?></p>
<p style="margin:0;font-size:13px;color:#5b6878;">Comunicado do <?= $this->e($condominium) ?>. Para deixar de receber comunicados por e-mail, fale com a administração.</p>
