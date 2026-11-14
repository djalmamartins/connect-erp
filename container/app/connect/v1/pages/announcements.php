<?php
/** Comunicados do condomínio para a unidade (ADR 0302). @var list<array> $announcements */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Comunicados</h1><p>Avisos da administração e do síndico.</p></header>
<?php if ($announcements === []): ?>
    <?php $this->insert('partials/empty', ['icon' => 'megaphone', 'text' => 'Nenhum comunicado por enquanto.']) ?>
<?php endif; ?>
<div class="app-notices">
<?php foreach ($announcements as $n): $long = mb_strlen($n['body']) > 360; ?>
    <article class="app-card app-notice" id="c<?= (int)$n['id'] ?>">
        <div class="app-notice__head">
            <span class="app-notice__icon" aria-hidden="true"><svg><use href="#i-megaphone"></use></svg></span>
            <div><h2><?= $this->e($n['subject']) ?></h2><small><?= F::dateTime($n['published_at']) ?></small></div>
        </div>
        <?php if ($long): ?>
            <details class="app-notice__more"><summary><span class="app-notice__body app-notice__body--clip"><?= nl2br($this->e(mb_substr($n['body'], 0, 320))) ?>…</span><span class="app-link-button">Ler tudo</span></summary>
                <div class="app-notice__body"><?= nl2br($this->e($n['body'])) ?></div></details>
        <?php else: ?>
            <div class="app-notice__body"><?= nl2br($this->e($n['body'])) ?></div>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
