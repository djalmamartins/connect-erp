<?php
/**
 * Cadastro rápido de unidades (AP-006, ADR 0140): gera a sequência (blocos × andares × unidades por andar)
 * e mostra a pré-visualização antes de criar. Pré-visualizar e criar usam a API /api/v1/unit-batches
 * (erp.js, data-erp-unit-batch); unidade que já existe no condomínio não é criada de novo.
 * @var list<array{id:int,name:string}> $batchCondominiums @var ?int $batchSelected @var array<string, string> $batchTypes
 */
$this->layout('default');
$units = erp_url('/condominios/unidades');
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($units) ?>">Voltar para Unidades</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups']['condominios']['label'] ?? 'Condomínios',
    'pageTitle' => 'Cadastro rápido de unidades',
    'pageLead' => 'Gere todas as unidades de um prédio de uma vez: informe os andares e quantas unidades há por andar, confira a lista e crie. Depois ajuste fração ideal e área em uma grade.',
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-unit-batch data-api="<?= $this->e(url('/api/v1/unit-batches')) ?>" data-units="<?= $this->e($units) ?>" data-measures="<?= $this->e(erp_url('/configuracoes/fracao-ideal')) ?>">
    <?php if ($batchCondominiums === []): ?>
        <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhum condomínio no seu acesso</strong><p>Cadastre o condomínio antes das unidades.</p></div></section>
    <?php else: ?>
    <form class="erp-card erp-form" data-unit-batch-form novalidate aria-labelledby="ub-title">
        <?= csrf_input() ?>
        <div class="erp-card__head"><div><h2 id="ub-title">Sequência</h2><p>Número = andar + posição. Ex.: andares 1 a 12, 8 por andar e 2 dígitos geram 101 a 108, 201 a 208 … 1201 a 1208 (96 unidades). No térreo (andar 0) fica só a posição: 01, 02…</p></div></div>
        <div data-form-error></div>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-6"><label for="ub-condominium">Condomínio</label>
                <select class="erp-select" id="ub-condominium" name="condominium_id" required>
                    <?php if ($batchSelected === null): ?><option value="">Selecione</option><?php endif; ?>
                    <?php foreach ($batchCondominiums as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $batchSelected ? ' selected' : '' ?>><?= $this->e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="erp-field erp-col-6"><label for="ub-blocks">Blocos ou torres <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="ub-blocks" name="blocks" maxlength="400" placeholder="Ex.: A, B, C" autocomplete="off"><span class="erp-field__hint">Separe por vírgula. Vazio se o condomínio não tem blocos. A sequência se repete em cada bloco.</span></div>

            <div class="erp-field erp-col-3"><label for="ub-from">Andar inicial</label><input class="erp-input" id="ub-from" name="floor_from" type="number" min="0" max="<?= \Source\Services\Erp\UnitBatch::MAX_FLOOR ?>" value="1" inputmode="numeric"><span class="erp-field__hint">0 = térreo</span></div>
            <div class="erp-field erp-col-3"><label for="ub-to">Andar final</label><input class="erp-input" id="ub-to" name="floor_to" type="number" min="0" max="<?= \Source\Services\Erp\UnitBatch::MAX_FLOOR ?>" value="12" inputmode="numeric"></div>
            <div class="erp-field erp-col-3"><label for="ub-per-floor">Unidades por andar</label><input class="erp-input" id="ub-per-floor" name="units_per_floor" type="number" min="1" max="<?= \Source\Services\Erp\UnitBatch::MAX_PER_FLOOR ?>" value="8" inputmode="numeric"></div>
            <div class="erp-field erp-col-3"><label for="ub-digits">Dígitos da posição</label>
                <select class="erp-select" id="ub-digits" name="digits"><option value="1">1 (11, 12…)</option><option value="2" selected>2 (101, 102…)</option><option value="3">3 (1001, 1002…)</option></select>
            </div>

            <div class="erp-field erp-col-3"><label for="ub-first">Primeira posição</label><select class="erp-select" id="ub-first" name="first_position"><option value="1" selected>1 (101, 102…)</option><option value="0">0 (100, 101…)</option></select></div>
            <div class="erp-field erp-col-3"><label for="ub-skip-floors">Andares a pular <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="ub-skip-floors" name="skip_floors" maxlength="200" placeholder="Ex.: 13" autocomplete="off"><span class="erp-field__hint">Separe por vírgula</span></div>
            <div class="erp-field erp-col-6"><label for="ub-skip-numbers">Números a pular <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="ub-skip-numbers" name="skip_numbers" maxlength="1000" placeholder="Ex.: 1207, 1208" autocomplete="off"><span class="erp-field__hint">Unidades que não existem no prédio (ex.: cobertura que ocupa dois apartamentos)</span></div>

            <div class="erp-field erp-col-6"><label for="ub-extra">Unidades avulsas <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="ub-extra" name="extra_numbers" maxlength="1000" placeholder="Ex.: Cobertura 1, Loja 1" autocomplete="off"><span class="erp-field__hint">Entram além da sequência, em cada bloco. Sem andares, só as avulsas são criadas.</span></div>
            <div class="erp-field erp-col-3"><label for="ub-type">Tipo</label><select class="erp-select" id="ub-type" name="type"><?php foreach ($batchTypes as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === 'apartamento' ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
            <div class="erp-field erp-col-3"><label for="ub-area">Área privativa (m²) <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="ub-area" name="private_area" inputmode="decimal" maxlength="14" placeholder="Ex.: 72,50" autocomplete="off"><span class="erp-field__hint">A mesma para todas; ajuste depois na grade</span></div>
        </div>
        <div class="erp-form__actions">
            <button type="submit" class="erp-btn erp-btn--primary"><?= $icon('search', 'erp-icon--sm') ?>Pré-visualizar</button>
        </div>
    </form>

    <section class="erp-card" data-unit-batch-preview hidden aria-labelledby="ub-preview-title">
        <div class="erp-card__head">
            <div><h2 id="ub-preview-title">Pré-visualização</h2><p data-unit-batch-summary role="status" aria-live="polite"></p></div>
            <button type="button" class="erp-btn erp-btn--primary" data-unit-batch-create><?= $icon('check', 'erp-icon--sm') ?><span data-unit-batch-create-label>Criar unidades</span></button>
        </div>
        <div class="erp-table-wrap" role="region" aria-labelledby="ub-preview-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Unidade</th><th scope="col">Bloco</th><th scope="col">Andar</th><th scope="col">Situação</th></tr></thead>
            <tbody data-unit-batch-rows></tbody>
        </table></div>
        <p class="erp-muted">É tudo ou nada: se alguma unidade não passar nas regras do cadastro, nenhuma é criada. As que já existem ficam como estão.</p>
    </section>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: docs/specs/pages/ap-006-unidades.md · ADR 0140</p>
