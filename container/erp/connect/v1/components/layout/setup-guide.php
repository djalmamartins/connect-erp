<?php
/**
 * Guia de cadastro do condomínio (ADR 0151): condomínio → unidades → proprietários →
 * síndico e conselho → áreas vinculadas → checklist. Aparece no topo das telas de cada
 * passo enquanto o guia estiver aberto (CondominiumSetup::GUIDE_KEY) e na visão geral.
 *
 * @var array{condominium:array{id:int,name:string,slug:string},steps:list<array>,current:?int} $guide
 * @var bool $inline  true = cartão da visão geral (sem botões de navegar/encerrar)
 */
$inline ??= false;
$steps = $guide['steps'];
$currentIndex = $guide['current'];
$condoId = (int)$guide['condominium']['id'];
$done = count(array_filter($steps, static fn (array $s): bool => $s['done']));
$next = null;
if ($currentIndex !== null && isset($steps[$currentIndex + 1])) {
    $next = $steps[$currentIndex + 1];
} elseif ($currentIndex === null) {
    $next = \Source\Services\Erp\CondominiumSetup::nextPending($steps);
}
$isLast = $currentIndex !== null && $currentIndex === count($steps) - 1;
?>
<section class="erp-card erp-setup-guide<?= $inline ? ' erp-setup-guide--inline' : '' ?>" aria-labelledby="setup-guide-title-<?= $inline ? 'inline' : 'bar' ?>" data-erp-setup-guide>
    <div class="erp-card__head">
        <div>
            <h2 id="setup-guide-title-<?= $inline ? 'inline' : 'bar' ?>"><?= $inline ? 'Cadastro do condomínio' : 'Cadastro de ' . $this->e($guide['condominium']['name']) ?></h2>
            <p><?= $done ?> de <?= count($steps) ?> passos feitos. Siga a ordem; o checklist fica por último, com o que falta e os prazos.</p>
        </div>
        <div class="erp-setup-guide__actions">
            <?php if ($inline): ?>
                <form method="post" action="<?= erp_url('/condominios/' . $condoId . '/guia') ?>"><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--sm erp-btn--primary"><?= $icon('chevron-right', 'erp-icon--sm') ?><?= $done < count($steps) ? 'Continuar o cadastro' : 'Abrir o guia' ?></button></form>
            <?php else: ?>
                <?php if ($next !== null && !$isLast): ?>
                    <a class="erp-btn erp-btn--sm erp-btn--primary" href="<?= erp_url($next['route']) ?>">Próximo: <?= $this->e($next['label']) ?><?= $icon('chevron-right', 'erp-icon--sm') ?></a>
                <?php endif; ?>
                <form method="post" action="<?= erp_url('/condominios/' . $condoId . '/guia/encerrar') ?>"><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--sm<?= $isLast ? ' erp-btn--primary' : '' ?>"><?= $isLast ? $icon('check', 'erp-icon--sm') . 'Concluir cadastro' : 'Fechar o guia' ?></button></form>
            <?php endif; ?>
        </div>
    </div>
    <ol class="erp-steps erp-setup-guide__steps">
        <?php foreach ($steps as $i => $step): $isCurrent = $i === $currentIndex; ?>
            <li class="<?= $isCurrent ? 'is-current' : '' ?><?= $step['done'] ? ' is-done' : '' ?>">
                <a href="<?= erp_url($step['route']) ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
                    <span aria-hidden="true"><?= $step['done'] && !$isCurrent ? $icon('check', 'erp-icon--sm') : $i + 1 ?></span>
                    <b><?= $this->e($step['label']) ?></b>
                    <small><?= $this->e($step['meta']) ?><span class="erp-sr-only"><?= $step['done'] ? ' (feito)' : ' (a fazer)' ?></span></small>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
