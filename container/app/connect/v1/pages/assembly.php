<?php
/** Uma assembleia: convocação, pauta, resultado e ata (ADR 0302). @var array $assembly ResidentPortal::assembly() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$a = $assembly;
[$label, $tone] = F::badge(F::ASSEMBLY, $a['status']);
$modes = ['presencial' => 'Presencial', 'virtual' => 'Virtual', 'hibrida' => 'Presencial e virtual'];
$isLink = (bool)preg_match('~^https://~i', $a['location']);
?>
<p><a href="<?= app_url('/assembleias') ?>" class="app-back">‹ Assembleias</a></p>
<header class="app-page-head">
    <h1><?= $this->e($a['title']) ?></h1>
    <p><span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span> <?= $a['kind'] === 'ordinaria' ? 'Assembleia ordinária' : 'Assembleia extraordinária' ?> · <?= $modes[$a['mode']] ?? $a['mode'] ?></p>
</header>
<div class="app-card">
    <ul class="app-facts">
        <li>1ª convocação: <?= F::dateTime($a['first_call_at']) ?></li>
        <?php if ($a['second_call_at']): ?><li>2ª convocação: <?= F::dateTime($a['second_call_at']) ?></li><?php endif; ?>
        <li>Local: <?php if ($isLink): ?><a href="<?= $this->e($a['location']) ?>" target="_blank" rel="noopener noreferrer">entrar na sala virtual</a><?php else: ?><?= $this->e($a['location']) ?><?php endif; ?></li>
    </ul>
</div>
<section class="app-section">
    <h2>Pauta</h2>
    <ol class="app-agenda">
    <?php foreach ($a['items'] as $item): ?>
        <li class="app-card">
            <strong><?= (int)$item['position'] ?>. <?= $this->e($item['title']) ?></strong>
            <?php if ($item['description'] !== ''): ?><p><?= nl2br($this->e($item['description'])) ?></p><?php endif; ?>
            <?php if ($item['approved'] !== null): ?><span class="app-badge app-badge--<?= $item['approved'] ? 'success' : 'danger' ?>"><?= $item['approved'] ? 'Aprovado' : 'Não aprovado' ?></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol>
</section>
<?php if ($a['minutes'] !== null && $a['minutes'] !== ''): ?>
    <section class="app-section">
        <h2>Ata</h2>
        <pre class="app-card app-minutes"><?= $this->e($a['minutes']) ?></pre>
    </section>
<?php endif; ?>
