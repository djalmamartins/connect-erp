<?php
/** Edital de convocação da assembleia (ADR 0302, ajustes de 14/11). @var array $assembly ResidentPortal::assembly() @var array $link */
use Source\Controllers\App\Resident\Format as F;

$this->layout('print');
$a = $assembly;
$kinds = ['ordinaria' => 'ordinária', 'extraordinaria' => 'extraordinária'];
$modes = ['presencial' => 'presencial', 'virtual' => 'virtual', 'hibrida' => 'presencial e virtual'];
?>
<article class="doc">
    <div class="doc-head">
        <img src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios">
        <small><?= $this->e($link['condominium_name']) ?></small>
    </div>
    <h1>Edital de convocação</h1>
    <p class="doc-muted">Assembleia geral <?= $kinds[$a['kind']] ?? $this->e($a['kind']) ?> · <?= $modes[$a['mode']] ?? $this->e($a['mode']) ?></p>
    <p>Ficam convocados os condôminos do <?= $this->e($link['condominium_name']) ?> para a assembleia geral <?= $kinds[$a['kind']] ?? $this->e($a['kind']) ?> “<?= $this->e($a['title']) ?>”.</p>
    <div class="doc-grid">
        <div><span>1ª convocação</span><?= F::dateTime($a['first_call_at']) ?></div>
        <?php if ($a['second_call_at']): ?><div><span>2ª convocação</span><?= F::dateTime($a['second_call_at']) ?></div><?php endif; ?>
        <div><span>Local</span><?= $this->e($a['location']) ?></div>
    </div>
    <h2>Ordem do dia</h2>
    <ol>
    <?php foreach ($a['items'] as $item): ?>
        <li><strong><?= $this->e($item['title']) ?></strong><?php if ($item['description'] !== ''): ?><br><span class="doc-muted"><?= nl2br($this->e($item['description'])) ?></span><?php endif; ?>
            <br><small class="doc-muted"><?= $item['vote_basis'] === 'fraction' ? 'Voto por fração ideal' : 'Um voto por unidade' ?> · <?= $this->e($item['quorum_label']) ?></small></li>
    <?php endforeach; ?>
    </ol>
    <p class="doc-muted">A unidade com conta vencida em aberto pode participar, mas não vota (Código Civil, art. 1.335, III). Quem for representar outra pessoa deve apresentar a procuração no início.</p>
    <div class="doc-sign"><hr>Administração do condomínio</div>
</article>
