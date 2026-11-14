<?php
/** Comunicados do condomínio para a unidade (ADR 0302). @var list<array> $announcements */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><h1>Comunicados</h1><p>Avisos da administração e do síndico.</p></header>
<?php if ($announcements === []): ?>
    <div class="app-card app-empty"><p>Nenhum comunicado por enquanto.</p></div>
<?php endif; ?>
<?php foreach ($announcements as $n): ?>
    <article class="app-card app-notice" id="c<?= (int)$n['id'] ?>">
        <small><?= F::dateTime($n['published_at']) ?></small>
        <h2><?= $this->e($n['subject']) ?></h2>
        <div class="app-notice__body"><?= nl2br($this->e($n['body'])) ?></div>
    </article>
<?php endforeach; ?>
