<?php
/** Busca no que o morador pode ver (ADR 0302, ajustes de 14/11). @var string $query @var list<array> $results ResidentFeed::search() */
use Source\Services\Resident\ResidentFeed;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Buscar</h1></header>
<form class="app-search" method="get" action="<?= app_url('/buscar') ?>" role="search">
    <svg aria-hidden="true"><use href="#i-search"></use></svg>
    <label class="app-sr" for="app-q">Buscar</label>
    <input id="app-q" type="search" name="q" value="<?= $this->e($query) ?>" maxlength="80" placeholder="Comunicados, documentos, encomendas, páginas…" autofocus>
</form>
<?php if (mb_strlen(ResidentFeed::fold($query)) < ResidentFeed::MIN_QUERY): ?>
    <p class="app-muted">Escreva pelo menos <?= ResidentFeed::MIN_QUERY ?> letras.</p>
<?php elseif ($results === []): ?>
    <div class="app-card app-empty"><p>Nada encontrado para “<?= $this->e($query) ?>”.</p></div>
<?php else: ?>
    <ul class="app-feed">
    <?php foreach ($results as $r): ?>
        <li class="app-card"><a href="<?= app_url($r['path']) ?>">
            <span class="app-feed__text"><strong><?= $this->e($r['title']) ?></strong><span><?= $this->e($r['detail']) ?></span></span>
            <span class="app-badge app-badge--neutral"><?= $this->e($r['kind_label']) ?></span>
            <svg aria-hidden="true"><use href="#i-chevron"></use></svg>
        </a></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
