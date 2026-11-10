<?php
/**
 * Ficha da pessoa, somente leitura (painel da unidade AP-003, ADR 0155): abre ao clicar no nome do
 * proprietário, inquilino ou morador. Mostra os dados do cadastro AP-005 (já liberados por
 * erp.residents.view) e as outras unidades da pessoa no escopo. Não tem formulário: para alterar,
 * o botão leva ao cadastro.
 * @var array $person item de people do painel @var array $unit unit do painel @var array<string, string> $relationships
 */
use Source\Support\Erp\BrDocument;
use Source\Support\Erp\RegistryFormat;

$modalId = 'person-card-' . (int)$person['id'];
$day = static fn (?string $date): string => $date ? date('d/m/Y', (int)strtotime($date)) : '—';
$document = $person['document'] ?? null;
$kind = $document === null ? null : (strlen((string)$document) === 14 ? 'Pessoa jurídica' : 'Pessoa física');
$facts = [
    'CPF/CNPJ' => $document !== null ? BrDocument::format((string)$document) : 'Não informado',
    'Tipo' => $kind ?? '—',
    'Vínculo' => $relationships[$person['relationship']] ?? (string)$person['relationship'],
    'Telefone' => ($person['phone'] ?? null) !== null ? RegistryFormat::phone((string)$person['phone']) : 'Não informado',
    'E-mail' => $person['email'] ?? 'Não informado',
    'Início do vínculo' => $day($person['starts_on'] ?? null),
    'Fim do vínculo' => $day($person['ends_on'] ?? null),
    'Boleto por e-mail' => !empty($person['billing_by_email']) ? 'Sim' : 'Não',
    'Comunicados por e-mail' => !empty($person['notices_opt_out']) ? 'Não quer receber' : 'Recebe',
    'Portal do morador' => !empty($person['has_portal_account']) ? 'Tem acesso' : 'Sem acesso',
];
$phoneDigits = BrDocument::digits((string)($person['phone'] ?? ''));
?>
<dialog class="erp-modal erp-modal--wide erp-person-card" id="<?= $this->e($modalId) ?>" aria-labelledby="<?= $this->e($modalId) ?>-title" data-erp-person-card>
    <div class="erp-modal__head">
        <div>
            <h2 id="<?= $this->e($modalId) ?>-title"><?= $this->e((string)$person['name']) ?></h2>
            <small class="erp-muted">Ficha da pessoa · somente leitura</small>
        </div>
        <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
    </div>
    <div class="erp-modal__body">
        <dl class="erp-import-facts erp-person-card__facts">
            <?php foreach ($facts as $label => $value): ?>
                <div><dt><?= $this->e($label) ?></dt><dd><?php if ($label === 'Telefone' && strlen($phoneDigits) >= 10): ?><a href="tel:+55<?= $this->e($phoneDigits) ?>"><?= $this->e((string)$value) ?></a><?php elseif ($label === 'E-mail' && ($person['email'] ?? null) !== null): ?><a href="mailto:<?= $this->e((string)$value) ?>"><?= $this->e((string)$value) ?></a><?php else: ?><?= $this->e((string)$value) ?><?php endif; ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <dl class="erp-import-facts erp-person-card__facts">
            <div class="erp-person-card__wide"><dt>Unidade</dt><dd><?= $this->e((string)$unit['label']) ?> · <?= $this->e((string)$unit['condominium_name']) ?></dd></div>
            <div class="erp-person-card__wide"><dt>Endereço da unidade</dt><dd><?= $this->e((string)($unit['address'] ?? 'Endereço do condomínio não cadastrado')) ?></dd></div>
        </dl>
        <h3 class="erp-panel-subtitle">Outras unidades</h3>
        <?php if (($person['other_units'] ?? []) === []): ?>
            <p class="erp-field__hint"><?= $document === null ? 'Sem CPF/CNPJ no cadastro, não dá para achar a pessoa em outras unidades.' : 'Nenhuma outra unidade nos seus condomínios.' ?></p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($person['other_units'] as $other): ?>
                    <li><span class="erp-list__icon"><?= $icon('building', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e($other['label']) ?></strong><small><?= $this->e($other['condominium_name']) ?></small></div><span class="erp-badge erp-badge--plain"><?= $this->e($relationships[$other['relationship']] ?? $other['relationship']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (($person['notes'] ?? null) !== null): ?>
            <h3 class="erp-panel-subtitle">Observações</h3>
            <p class="erp-person-card__notes"><?= nl2br($this->e((string)$person['notes'])) ?></p>
        <?php endif; ?>
    </div>
    <div class="erp-modal__foot">
        <button type="button" class="erp-btn" data-erp-modal-close>Fechar</button>
        <a class="erp-btn erp-btn--primary" href="<?= erp_url('/cadastros/proprietarios-e-inquilinos/' . (int)$person['id']) ?>"><?= $icon('edit', 'erp-icon--sm') ?>Abrir cadastro para alterar</a>
    </div>
</dialog>
