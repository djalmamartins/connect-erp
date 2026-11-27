<?php
/**
 * "Escolher a pessoa" no cadastro Síndico e conselho (ADR 0683, pedido do Djalma, 27/11/2026). Mostra os moradores do
 * prédio escolhido no formulário e os síndicos profissionais da Connect, com busca; ao clicar, o erp.js
 * (data-erp-board-picker) preenche nome, CPF/CNPJ, e-mail, telefone e unidade e grava o vínculo no campo escondido
 * (resident_id ou professional_manager_id). As mesmas pessoas aparecem como sugestão ao digitar o nome.
 * Conselho (fiscal, consultivo e suplente) é só de moradores: a aba de síndicos profissionais some para esses cargos.
 * Os dados vêm de GET /api/v1/board-members/candidates; o servidor confere tudo de novo ao gravar.
 * @var array{api:string, newProfessional:string, residentsAllowed:bool} $picker
 */
?>
<section class="erp-card erp-board-picker" aria-labelledby="board-picker-title" data-erp-board-picker data-api="<?= $this->e($picker['api']) ?>">
    <div class="erp-card__head"><div><h2 id="board-picker-title">Escolher a pessoa</h2>
        <p>Clique para preencher o formulário com os dados da pessoa.</p></div></div>
    <div class="erp-tabs erp-board-picker__tabs" role="tablist" aria-label="De onde vem a pessoa">
        <button type="button" role="tab" id="board-picker-tab-residents" aria-controls="board-picker-panel" aria-selected="true" data-board-picker-tab="residents">Moradores do prédio</button>
        <button type="button" role="tab" id="board-picker-tab-professionals" aria-controls="board-picker-panel" aria-selected="false" data-board-picker-tab="professionals">Síndicos profissionais</button>
    </div>
    <div id="board-picker-panel" role="tabpanel" aria-labelledby="board-picker-tab-residents" class="erp-board-picker__panel">
        <label class="erp-field__label" for="board-picker-search">Buscar</label>
        <input class="erp-input" id="board-picker-search" type="search" autocomplete="off" placeholder="Nome, unidade ou CPF/CNPJ" data-board-picker-search>
        <p class="erp-muted erp-board-picker__status" data-board-picker-status aria-live="polite">Escolha o condomínio no formulário para ver os moradores.</p>
        <ul class="erp-board-picker__list" data-board-picker-list aria-label="Pessoas"></ul>
        <?php if (!$picker['residentsAllowed']): ?>
            <p class="erp-field__hint" data-board-picker-no-residents>Para ver os moradores, peça ao administrador o acesso a Proprietários e inquilinos.</p>
        <?php endif; ?>
        <p class="erp-board-picker__new" data-board-picker-new hidden>
            <a class="erp-btn erp-btn--sm" href="<?= $this->e($picker['newProfessional']) ?>" target="_blank" rel="noopener"><?= $icon('plus', 'erp-icon--sm') ?>Cadastrar síndico profissional</a>
            <button type="button" class="erp-link-button" data-board-picker-reload>Atualizar a lista</button>
        </p>
    </div>
</section>
