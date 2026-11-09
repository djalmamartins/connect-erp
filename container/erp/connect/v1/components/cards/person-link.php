<?php
/**
 * Proprietários e inquilinos (AP-005, ADR 0162): reaproveitar uma pessoa já cadastrada e vinculá-la
 * a várias unidades de uma vez (ex.: quem aluga 4 salas, ou o dono de unidades em condomínios
 * diferentes). Usa GET /api/v1/people/search e POST /api/v1/people/{id}/links pelo erp.js
 * (data-erp-person-link); o servidor confere escopo, permissão e CSRF de novo.
 *
 * Cadastro novo: busca por nome ou CPF/CNPJ. Cadastro aberto: a própria pessoa já vem escolhida.
 *
 * @var ?array{id:int,name:string} $person pessoa do cadastro aberto (null no cadastro novo)
 * @var list<array{id:int,condominium_id:int,label:string}> $units unidades ativas do escopo
 * @var list<array{id:int,name:string}> $condominiums
 */
$condominiumNames = array_column($condominiums, 'name', 'id');
$byCondominium = [];
foreach ($units as $unitOption) {
    $byCondominium[$unitOption['condominium_id']][] = $unitOption;
}
?>
<section class="erp-card erp-person-link" aria-labelledby="person-link-title" data-erp-person-link
         data-search="<?= $this->e(url('/api/v1/people/search')) ?>"
         data-links="<?= $this->e(url('/api/v1/people/{id}/links')) ?>"
         <?= $person !== null ? 'data-person-id="' . (int)$person['id'] . '" data-person-name="' . $this->e($person['name']) . '"' : '' ?>>
    <?= csrf_input() ?>
    <div class="erp-card__head"><div>
        <h2 id="person-link-title"><?= $person === null ? 'Pessoa já cadastrada?' : 'Vincular a mais unidades' ?></h2>
        <p><?= $person === null
            ? 'Busque pelo nome ou CPF/CNPJ para reaproveitar o cadastro e vincular a pessoa a uma ou várias unidades, inclusive de outros condomínios.'
            : 'Vincule ' . $this->e($person['name']) . ' a outras unidades de uma vez, com os mesmos dados de contato.' ?></p>
    </div></div>

    <?php if ($person === null): ?>
        <div class="erp-field">
            <label for="person-link-q">Buscar pessoa</label>
            <input class="erp-input" id="person-link-q" type="search" autocomplete="off" minlength="3" maxlength="80" placeholder="Nome, CPF ou CNPJ (mínimo 3 caracteres)" data-person-q>
            <span class="erp-field__hint" data-person-status aria-live="polite">Não achou? Preencha o formulário ao lado para cadastrar uma pessoa nova.</span>
        </div>
        <ul class="erp-list erp-person-link__results" data-person-results></ul>
    <?php endif; ?>

    <form class="erp-person-link__form" data-person-form<?= $person === null ? ' hidden' : '' ?>>
        <div class="erp-person-link__chosen" data-person-chosen></div>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-6">
                <label for="person-link-role">Vínculo</label>
                <select class="erp-select" id="person-link-role" name="role" required>
                    <option value="owner">Proprietário</option>
                    <option value="tenant">Inquilino</option>
                </select>
            </div>
            <div class="erp-field erp-col-6">
                <label for="person-link-start">Início do vínculo</label>
                <input class="erp-input" id="person-link-start" type="date" name="starts_at" min="1900-01-01" max="2100-12-31">
            </div>
        </div>
        <fieldset class="erp-person-link__units">
            <legend>Unidades <small data-person-count>(nenhuma marcada)</small></legend>
            <?php if ($units === []): ?>
                <p class="erp-field__hint">Nenhuma unidade ativa nos seus condomínios.</p>
            <?php else: ?>
                <input class="erp-input erp-input--sm" type="search" placeholder="Filtrar unidades" aria-label="Filtrar unidades" data-person-unit-filter>
                <div class="erp-person-link__unit-list">
                    <?php foreach ($byCondominium as $condominiumId => $group): ?>
                        <div class="erp-person-link__condo" data-person-condo>
                            <strong><?= $this->e((string)($condominiumNames[$condominiumId] ?? ('Condomínio ' . $condominiumId))) ?></strong>
                            <?php foreach ($group as $unitOption): ?>
                                <label class="erp-check" data-person-unit><input type="checkbox" name="unit_ids" value="<?= (int)$unitOption['id'] ?>"> <?= $this->e($unitOption['label']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <div data-form-error></div>
        <div data-person-result aria-live="polite"></div>
        <div class="erp-form__actions">
            <?php if ($person === null): ?><button type="button" class="erp-btn" data-person-clear>Buscar outra pessoa</button><?php endif; ?>
            <button type="submit" class="erp-btn erp-btn--primary" data-person-submit><?= $icon('plus', 'erp-icon--sm') ?>Vincular às unidades marcadas</button>
        </div>
    </form>
</section>
