<?php
/** Documentos liberados para os moradores (ADR 0302). @var list<array> $documents */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$groups = [];
foreach ($documents as $d) {
    $groups[$d['folder_label']][] = $d;
}
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Documentos</h1><p>Convenção, regimento interno, atas e outros documentos do condomínio.</p></header>
<?php if ($documents === []): ?><div class="app-card app-empty"><p>A administração ainda não liberou documentos para os moradores.</p></div><?php endif; ?>
<?php foreach ($groups as $folder => $items): ?>
    <section class="app-section">
        <h2><?= $this->e($folder) ?></h2>
        <ul class="app-list">
        <?php foreach ($items as $d): ?>
            <li><a class="app-card app-row" href="<?= app_url('/documentos/' . $d['id']) ?>">
                <span><strong><?= $this->e($d['title']) ?></strong><small><?= F::date($d['uploaded_at']) ?> · <?= number_format($d['size_bytes'] / 1024, 0, ',', '.') ?> KB</small></span>
                <svg class="app-row__icon" aria-hidden="true"><use href="#i-download"></use></svg>
            </a></li>
        <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
