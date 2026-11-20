<?php
/** Comunicado geral (AP-111, ADR 0059). Variáveis: $first_name, $subject, $body, $condominium. Texto do Moves Editor limpo pelo RichText (ADR 0610); texto antigo é escapado e as quebras viram <br>. */
$this->layout('default', ['subject' => $subject ?? 'Comunicado do condomínio', 'preheader' => 'Comunicado da administração do condomínio.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<div style="margin:0 0 16px;"><?= \Source\Support\RichText::html((string)($body ?? '')) ?></div>
<?php $this->insert('partials/note', ['text' => "Comunicado do {$condominium}. Para deixar de receber comunicados por e-mail, fale com a administração."]); ?>
