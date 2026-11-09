<?php
/**
 * Fração ideal e área em grade (AP-167, ADR 0140) com dados reais: todas as unidades ativas do condomínio
 * da barra superior, editáveis de uma vez ($measures de Panel::unitMeasuresData, o mesmo serviço de
 * GET /api/v1/unit-measures). Dividir igualmente ou pela área só preenche a grade (POST
 * /api/v1/unit-measures/distribute); gravar é PUT /api/v1/unit-measures (erp.js, data-erp-unit-measures).
 * @var array $currentPage @var ?array $measures @var ?string $measuresError @var bool $canManageMeasures
 */
$this->layout('default');
$context = \Source\Support\Erp\CondominiumContext::current();
$rows = $measures['units'] ?? [];
$fraction = static fn (?float $v): string => $v === null ? '' : number_format($v, 6, ',', '');
$area = static fn (?float $v): string => $v === null ? '' : number_format($v, 2, ',', '');
$percent = static fn (float $v): string => number_format($v * 100, 4, ',', '.') . '%';
$canEdit = $canManageMeasures && $rows !== [];
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e(erp_url('/condominios/unidades')) ?>">Unidades</a>
    <?php if ($canManageMeasures): ?><a class="erp-btn" href="<?= $this->e(erp_url('/condominios/unidades/cadastro-rapido')) ?>"><?= $icon('plus', 'erp-icon--sm') ?>Cadastro rápido</a><?php endif; ?>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => 'Fração ideal e área',
    'pageLead' => 'Fração ideal e área privativa de todas as unidades do condomínio em uma grade. Divida igualmente ou pela área e ajuste o que for diferente. A soma das frações precisa dar 100%.',
    'pageActions' => $actions,
]);
?>
<?php if ($measuresError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($measuresError) ?></div></div>
<?php elseif ($context === null): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>A fração ideal é de cada condomínio. Escolha o condomínio na barra superior.</p></div></section>
<?php elseif ($rows === []): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhuma unidade ativa</strong><p>Cadastre as unidades<?= $canManageMeasures ? ' de uma vez pelo <a href="' . $this->e(erp_url('/condominios/unidades/cadastro-rapido')) . '">cadastro rápido</a>' : ' em Condomínios › Unidades' ?>.</p></div></section>
<?php else: ?>
<div class="erp-stack" data-erp-unit-measures data-api="<?= $this->e(url('/api/v1/unit-measures')) ?>" data-condominium="<?= (int)$measures['condominium_id'] ?>">
    <?= csrf_input() ?>
    <section class="erp-grid erp-grid--kpi" aria-label="Resumo das frações de <?= $this->e((string)($context['name'] ?? '')) ?>">
        <?php foreach ([
            ['label' => 'Unidades ativas', 'value' => (string)count($rows), 'meta' => (string)($context['name'] ?? ''), 'icon' => 'building', 'tone' => '', 'trend' => null],
            ['label' => 'Soma das frações', 'value' => $percent((float)$measures['fraction_sum']), 'meta' => $measures['complete'] ? 'Fecha 100%' : 'Precisa dar 100%', 'icon' => 'chart', 'tone' => $measures['complete'] ? 'success' : 'warning', 'trend' => null],
            ['label' => 'Sem fração', 'value' => (string)$measures['missing_fraction'], 'meta' => 'unidade(s)', 'icon' => 'alert', 'tone' => $measures['missing_fraction'] > 0 ? 'warning' : '', 'trend' => null],
            ['label' => 'Área privativa total', 'value' => number_format((float)$measures['area_sum'], 2, ',', '.') . ' m²', 'meta' => $measures['missing_area'] . ' sem área', 'icon' => 'home', 'tone' => 'info', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="um-sheet">
        <form data-unit-measures-form novalidate>
            <div class="erp-card__head">
                <div><h2 id="um-sheet">Unidades de <?= $this->e((string)($context['name'] ?? '')) ?></h2><p>Fração em decimal (0,012500) ou em porcentagem (1,25%). Enter vai para a linha de baixo.</p></div>
                <?php if ($canEdit): ?>
                    <div class="erp-meter-head-actions">
                        <button type="button" class="erp-btn" data-unit-measures-distribute="equal" title="1 ÷ número de unidades, com a soma fechando 100%">Dividir igualmente</button>
                        <button type="button" class="erp-btn" data-unit-measures-distribute="area" title="Área da unidade ÷ área total; exige área em todas">Dividir pela área</button>
                    </div>
                <?php endif; ?>
            </div>
            <div data-form-error></div>
            <div class="erp-table-wrap" role="region" aria-labelledby="um-sheet" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Unidade</th><th scope="col">Andar</th><th scope="col">Tipo</th><th scope="col" class="is-num">Área privativa (m²)</th><th scope="col" class="is-num">Fração ideal</th><th scope="col" class="is-num">%</th></tr></thead>
                <tbody><?php foreach ($rows as $u): ?>
                    <tr data-unit-row="<?= (int)$u['id'] ?>">
                        <th scope="row" class="erp-nowrap"><?= $this->e($u['label']) ?></th>
                        <td><?= $this->e((string)($u['floor'] ?? '—')) ?></td>
                        <td><?= $this->e(\Source\Services\Erp\UnitBatch::TYPES[$u['type']] ?? $u['type']) ?></td>
                        <td class="is-num"><?php if ($canEdit): ?><input class="erp-input erp-meter-input" name="private_area" inputmode="decimal" autocomplete="off" maxlength="14" aria-label="Área de <?= $this->e($u['label']) ?>" data-original="<?= $this->e($area($u['private_area'])) ?>" value="<?= $this->e($area($u['private_area'])) ?>"><?php else: ?><?= $u['private_area'] === null ? '—' : $this->e($area($u['private_area'])) ?><?php endif; ?></td>
                        <td class="is-num"><?php if ($canEdit): ?><input class="erp-input erp-meter-input" name="ideal_fraction" inputmode="decimal" autocomplete="off" maxlength="12" aria-label="Fração ideal de <?= $this->e($u['label']) ?>" data-original="<?= $this->e($fraction($u['ideal_fraction'])) ?>" value="<?= $this->e($fraction($u['ideal_fraction'])) ?>"><?php else: ?><?= $u['ideal_fraction'] === null ? '—' : $this->e($fraction($u['ideal_fraction'])) ?><?php endif; ?></td>
                        <td class="is-num" data-unit-percent><?= $u['ideal_fraction'] === null ? '—' : $this->e($percent((float)$u['ideal_fraction'])) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><th scope="row" colspan="3">Total</th><td class="is-num" data-unit-area-total><?= number_format((float)$measures['area_sum'], 2, ',', '.') ?></td><td class="is-num" data-unit-fraction-total><?= number_format((float)$measures['fraction_sum'], 6, ',', '') ?></td><td class="is-num" data-unit-percent-total><?= $this->e($percent((float)$measures['fraction_sum'])) ?></td></tr></tfoot>
            </table></div>
            <?php if ($canEdit): ?>
                <div class="erp-form__actions erp-meter-actions">
                    <span class="erp-muted" data-meter-changes data-unit-measures-changes role="status" aria-live="polite">Nenhuma alteração.</span>
                    <button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar</button>
                </div>
                <p class="erp-muted">Pode salvar com unidades ainda sem fração; com todas preenchidas, a soma precisa dar 100%. Cada unidade alterada fica no histórico de auditoria.</p>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?> · ADR 0140</p>
