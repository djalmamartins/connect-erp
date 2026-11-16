<?php
/**
 * Termo de entrada ou de saída pronto para imprimir (AP-006, ADR 0311). O HTML do texto vem de UnitMoveTerms::fill(),
 * que escapa cada pedaço; aqui só entra a moldura do documento.
 * @var ?array $moveTerm @var ?string $moveTermError @var ?int $moveTermUnit
 */
$this->layout('default');
$back = erp_url('/condominios/painel-da-unidade') . ($moveTermUnit ? '?unidade=' . (int)$moveTermUnit : '');
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($back) ?>">Voltar ao painel da unidade</a>
    <?php if ($moveTerm !== null): ?><button type="button" class="erp-btn erp-btn--primary" data-erp-print><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button><?php endif; ?>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups']['condominios']['label'] ?? 'Condomínios',
    'pageTitle' => $moveTerm['title'] ?? 'Termo de entrada/saída',
    'pageLead' => $moveTerm !== null ? 'Confira e imprima. O texto vem de Termo de entrada/saída, na lista de unidades.' : null,
    'pageActions' => $actions,
]);
?>
<?php if ($moveTerm === null): ?>
    <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e((string)$moveTermError) ?></strong><a href="<?= $this->e($back) ?>">Voltar ao painel da unidade</a></div></div>
<?php else: ?>
    <article class="erp-card erp-unit-doc erp-move-term" aria-labelledby="move-term-title" data-move-term-kind="<?= $this->e($moveTerm['kind']) ?>">
        <header class="erp-unit-doc__head">
            <div><span class="erp-unit-doc__issuer"><?= $this->e($moveTerm['condominium']) ?></span><h2 id="move-term-title"><?= $this->e($moveTerm['title']) ?></h2></div>
            <div class="erp-unit-doc__number">Unidade <?= $this->e($moveTerm['unit']) ?></div>
        </header>
        <div class="erp-unit-doc__text erp-move-term__text"><?= $moveTerm['html'] ?></div>
    </article>
<?php endif; ?>
