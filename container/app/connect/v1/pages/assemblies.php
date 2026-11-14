<?php
/** Assembleias do condomínio (ADR 0302). @var list<array> $assemblies */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Assembleias</h1><p>Editais, pauta, resultado de cada item e a ata.</p></header>
<?php if ($assemblies === []): ?><?php $this->insert('partials/empty', ['icon' => 'users', 'text' => 'Nenhuma assembleia convocada.']) ?><?php endif; ?>
<ul class="app-items">
<?php foreach ($assemblies as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']);
    $this->insert('partials/item', ['date' => $a['first_call_at'], 'title' => $a['title'], 'href' => app_url('/assembleias/' . $a['id']),
        'lines' => [($a['kind'] === 'ordinaria' ? 'Ordinária' : 'Extraordinária') . ' · ' . F::dateTime($a['first_call_at']), (string)$a['location']],
        'badge' => [$label, $tone]]);
endforeach; ?>
</ul>
