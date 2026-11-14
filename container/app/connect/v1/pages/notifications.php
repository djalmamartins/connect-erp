<?php
/** Sininho: avisos do vínculo (ADR 0302, ajustes de 14/11). @var list<array> $items ResidentFeed::notifications() @var ?string $seenAt */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Avisos</h1><p>O que pede a sua atenção: contas vencidas, encomendas, visitas, comunicados, assembleias e reservas.</p></header>
<?php if ($items === []): ?>
    <div class="app-card app-empty"><p>Nenhum aviso agora. Tudo em dia.</p></div>
<?php endif; ?>
<ul class="app-feed">
<?php foreach ($items as $item): $new = \Source\Services\Resident\ResidentFeed::isNew($item, $seenAt); ?>
    <li class="app-card"><a href="<?= app_url($item['path']) ?>">
        <span class="app-feed__dot app-feed__dot--<?= $this->e($item['tone']) ?>" aria-hidden="true"></span>
        <span class="app-feed__text"><strong><?= $this->e($item['title']) ?></strong><span><?= $this->e($item['detail']) ?></span></span>
        <span class="app-feed__meta"><?php if ($new): ?><span class="app-feed__new">Novo</span><?php endif; ?><?= F::date(substr($item['at'], 0, 10)) ?></span>
        <svg aria-hidden="true"><use href="#i-chevron"></use></svg>
    </a></li>
<?php endforeach; ?>
</ul>
