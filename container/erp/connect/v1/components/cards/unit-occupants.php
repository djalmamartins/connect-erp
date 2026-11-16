<?php
/**
 * Inquilino e proprietário da unidade (AP-006, ADR 0311; modelos 4, 5 e 6 da revisão de 15/11). Mostra quem está na
 * unidade e abre a troca: busca a pessoa (GET /api/v1/people/search) ou cadastra uma nova ali mesmo ("+"), pergunta as
 * datas de saída e entrada e o que fazer com as faturas abertas, e grava por POST /api/v1/units/{id}/occupant-change
 * (erp.js, data-erp-unit-occupants). O servidor confere escopo, permissões e CSRF de novo.
 * @var array{unitId:int, owner:list<array>, tenant:list<array>, payer_role:?string, canInvoices:bool} $occupants
 */
use Source\Services\Erp\UnitOccupantChange;

$labels = ['tenant' => ['Inquilino', 'Trocar inquilino', 'Informar inquilino'], 'owner' => ['Proprietário', 'Trocar proprietário', 'Informar proprietário']];
$payer = ['tenant' => 'o inquilino', 'owner' => 'o proprietário'];
$today = date('Y-m-d');
?>
<section class="erp-card erp-unit-occupants" aria-labelledby="unit-occupants-title" data-erp-unit-occupants
         data-search="<?= $this->e(url('/api/v1/people/search')) ?>" data-api="<?= $this->e(url('/api/v1/units/' . (int)$occupants['unitId'] . '/occupant-change')) ?>">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="unit-occupants-title">Inquilino e proprietário</h2>
        <p><?= $occupants['payer_role'] === null ? 'A unidade tem responsável financeiro: as faturas saem em nome dele.' : 'As faturas saem em nome d' . $payer[$occupants['payer_role']] . ' (Responsável pela cobrança).' ?></p></div></div>
    <?php foreach (['tenant', 'owner'] as $role): $people = $occupants[$role]; ?>
        <div class="erp-unit-occupants__role">
            <span class="erp-field__label"><?= $labels[$role][0] ?></span>
            <?php if ($people === []): ?><p class="erp-muted">Nenhum <?= mb_strtolower($labels[$role][0]) ?> ativo.</p><?php else: ?>
                <ul class="erp-unit-occupants__people"><?php foreach ($people as $p): ?><li><strong><?= $this->e($p['name']) ?></strong><?php if ($p['starts_on'] !== null): ?> <span class="erp-muted">desde <?= $this->e(date('d/m/Y', (int)strtotime((string)$p['starts_on']))) ?></span><?php endif; ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <button type="button" class="erp-btn erp-btn--sm" data-unit-occupant-open="<?= $role ?>" data-has-current="<?= $people === [] ? '0' : '1' ?>" data-label="<?= $this->e(mb_strtolower($labels[$role][0])) ?>"><?= $icon($people === [] ? 'plus' : 'edit', 'erp-icon--sm') ?><?= $labels[$role][$people === [] ? 2 : 1] ?></button>
        </div>
    <?php endforeach; ?>

    <dialog class="erp-modal erp-modal--wide" id="unit-occupant-dialog" aria-labelledby="unit-occupant-title">
        <form method="dialog" novalidate data-unit-occupant-form>
            <input type="hidden" name="role" value="tenant">
            <div class="erp-modal__head"><h2 id="unit-occupant-title">Alteração de inquilino/proprietário</h2><button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button></div>
            <div class="erp-modal__body erp-stack">
                <div data-form-error></div>
                <fieldset class="erp-fieldset">
                    <legend data-unit-occupant-legend>Novo inquilino</legend>
                    <div data-unit-occupant-pick>
                        <label class="erp-field__label" for="uo-search">Buscar pessoa já cadastrada</label>
                        <div class="erp-inline-field">
                            <input class="erp-input" id="uo-search" type="search" autocomplete="off" placeholder="Nome ou CPF/CNPJ (3 letras ou mais)" data-unit-occupant-search>
                            <button type="button" class="erp-btn" data-unit-occupant-new aria-label="Cadastrar pessoa nova" title="Cadastrar pessoa nova"><?= $icon('plus', 'erp-icon--sm') ?>Nova pessoa</button>
                        </div>
                        <ul class="erp-unit-occupants__results" data-unit-occupant-results role="listbox" aria-label="Pessoas encontradas"></ul>
                        <p class="erp-muted" data-unit-occupant-chosen hidden></p>
                        <input type="hidden" name="person_id" value="">
                    </div>
                    <div class="erp-grid erp-grid--2" data-unit-occupant-quick hidden>
                        <div class="erp-field"><label class="erp-field__label" for="uo-name">Nome completo ou razão social *</label><input class="erp-input" id="uo-name" name="person_name" maxlength="160"></div>
                        <div class="erp-field"><label class="erp-field__label" for="uo-document">CPF/CNPJ</label><input class="erp-input" id="uo-document" name="person_document" inputmode="numeric" maxlength="18" data-mask="document" data-erp-cnpj-lookup data-cnpj-map="<?= $this->e((string)json_encode(['person_name' => 'razao_social', 'person_email' => 'email', 'person_phone' => 'phone'])) ?>"><span class="erp-field__error" aria-live="polite"></span></div>
                        <div class="erp-field"><label class="erp-field__label" for="uo-email">E-mail</label><input class="erp-input" id="uo-email" name="person_email" type="email" maxlength="254"></div>
                        <div class="erp-field"><label class="erp-field__label" for="uo-phone">Telefone</label><input class="erp-input" id="uo-phone" name="person_phone" inputmode="tel" maxlength="20"></div>
                        <p class="erp-field__hint erp-span-all"><button type="button" class="erp-link-button" data-unit-occupant-back>Voltar para a busca</button></p>
                    </div>
                </fieldset>
                <fieldset class="erp-fieldset" data-unit-occupant-invoices>
                    <legend>Deseja vincular as faturas abertas ao novo <span data-unit-occupant-role>inquilino</span>?</legend>
                    <?php foreach (UnitOccupantChange::INVOICE_OPTIONS as $value => $label): ?>
                        <label class="erp-check"><input type="radio" name="invoices" value="<?= $value ?>"<?= $value === 'none' ? ' checked' : '' ?><?= $value !== 'none' && !$occupants['canInvoices'] ? ' disabled' : '' ?>> <?= $this->e($label) ?></label>
                    <?php endforeach; ?>
                    <p class="erp-field__hint">Só muda quando a pessoa trocada é quem paga a unidade. Conta com boleto já gerado continua com o pagador atual.<?= $occupants['canInvoices'] ? '' : ' Alterar faturas exige permissão no financeiro.' ?></p>
                </fieldset>
                <label class="erp-check"><input type="checkbox" name="history" value="1" checked data-unit-occupant-history> Registrar histórico de entrada/saída</label>
                <div class="erp-grid erp-grid--2" data-unit-occupant-dates>
                    <div class="erp-field" data-unit-occupant-exit><label class="erp-field__label" for="uo-exit">Data de saída do antigo <span data-unit-occupant-role>inquilino</span></label><input class="erp-input" id="uo-exit" name="exit_on" type="date" value="<?= $today ?>"></div>
                    <div class="erp-field"><label class="erp-field__label" for="uo-entry">Data de entrada do novo <span data-unit-occupant-role>inquilino</span></label><input class="erp-input" id="uo-entry" name="entry_on" type="date" value="<?= $today ?>"></div>
                </div>
            </div>
            <div class="erp-modal__foot">
                <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
                <button type="submit" class="erp-btn erp-btn--primary" data-unit-occupant-save><?= $icon('check', 'erp-icon--sm') ?>Salvar</button>
            </div>
        </form>
    </dialog>
</section>
