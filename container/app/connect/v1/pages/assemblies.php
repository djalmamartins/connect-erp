<?php
/** Assembleias do condomínio (ADR 0302). @var list<array> $assemblies */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><h1>Assembleias</h1><p>Editais, pauta, resultado de cada item e a ata.</p></header>
<?php if ($assemblies === []): ?><div class="app-card app-empty"><p>Nenhuma assembleia convocada.</p></div><?php endif; ?>
<ul class="app-list">
<?php foreach ($assemblies as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']); ?>
    <li><a class="app-card app-row" href="<?= app_url('/assembleias/' . $a['id']) ?>">
        <span><strong><?= $this->e($a['title']) ?></strong><small><?= $a['kind'] === 'ordinaria' ? 'Ordinária' : 'Extraordinária' ?> · <?= F::dateTime($a['first_call_at']) ?></small></span>
        <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
    </a></li>
<?php endforeach; ?>
</ul>
