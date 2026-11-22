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
<?php if ($documents === []): ?><?php $this->insert('partials/empty', ['icon' => 'file', 'text' => 'A administração ainda não liberou documentos para os moradores.']) ?><?php endif; ?>
<?php foreach ($groups as $folder => $items): ?>
    <section class="app-section">
        <h2><?= $this->e($folder) ?></h2>
        <ul class="app-items">
        <?php foreach ($items as $d):
            $kb = max(1, (int)round($d['size_bytes'] / 1024));
            $this->insert('partials/item', ['icon' => 'file', 'title' => $d['title'], 'href' => app_url('/documentos/' . $d['id']), 'external' => true,
                'lines' => [F::date($d['uploaded_at']) . ' · ' . ($kb >= 1024 ? number_format($kb / 1024, 1, ',', '.') . ' MB' : $kb . ' KB')]]);
        endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
