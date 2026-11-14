<?php
/** Assembleias do condomínio (ADR 0302). @var list<array> $assemblies */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Assembleias</h1><p>Editais, pauta, resultado de cada item e a ata.</p></header>
<?php if ($assemblies === []): ?><div class="app-card app-empty"><p>Nenhuma assembleia convocada.</p></div><?php endif; ?>
<ul class="app-list app-list--spaced">
<?php foreach ($assemblies as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']); ?>
    <li><a class="app-card app-assembly" href="<?= app_url('/assembleias/' . $a['id']) ?>">
        <span class="app-date" aria-hidden="true"><strong><?= substr($a['first_call_at'], 8, 2) ?></strong><?= $months[(int)substr($a['first_call_at'], 5, 2) - 1] ?></span>
        <span class="app-assembly__text"><strong><?= $this->e($a['title']) ?></strong>
            <small><?= $a['kind'] === 'ordinaria' ? 'Ordinária' : 'Extraordinária' ?> · <?= F::dateTime($a['first_call_at']) ?></small>
            <small><?= $this->e($a['location']) ?></small></span>
        <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
        <svg class="app-charge__chevron" aria-hidden="true"><use href="#i-chevron"></use></svg>
    </a></li>
<?php endforeach; ?>
</ul>
