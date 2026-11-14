<?php
/** Busca no que o morador pode ver (ADR 0302, ajustes de 14/11). @var string $query @var list<array> $results ResidentFeed::search() */
use Source\Services\Resident\ResidentFeed;

$this->layout('default');
$icons = ['page' => 'grid', 'announcement' => 'megaphone', 'assembly' => 'users', 'document' => 'file', 'delivery' => 'box'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Buscar</h1></header>
<form class="app-search" method="get" action="<?= app_url('/buscar') ?>" role="search">
    <svg aria-hidden="true"><use href="#i-search"></use></svg>
    <label class="app-sr" for="app-q">Buscar</label>
    <input id="app-q" type="search" name="q" value="<?= $this->e($query) ?>" maxlength="80" placeholder="Comunicados, documentos, encomendas, páginas…" autofocus>
</form>
<?php if (mb_strlen(ResidentFeed::fold($query)) < ResidentFeed::MIN_QUERY): ?>
    <?php $this->insert('partials/empty', ['icon' => 'search', 'text' => 'Escreva pelo menos ' . ResidentFeed::MIN_QUERY . ' letras para buscar.']) ?>
<?php elseif ($results === []): ?>
    <?php $this->insert('partials/empty', ['icon' => 'search', 'text' => 'Nada encontrado para “' . $query . '”.']) ?>
<?php else: ?>
    <ul class="app-items">
    <?php foreach ($results as $r):
        $this->insert('partials/item', ['icon' => $icons[$r['kind']] ?? 'search', 'title' => $r['title'], 'lines' => [$r['kind_label'] . ($r['detail'] !== '' ? ' · ' . $r['detail'] : '')],
            'href' => app_url($r['path'])]);
    endforeach; ?>
    </ul>
<?php endif; ?>
