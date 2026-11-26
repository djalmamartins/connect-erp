<?php
/**
 * Nova proposta no ERP (CN-011, ADR 0650): para pedido que chegou por telefone, WhatsApp, e-mail, indicação ou visita.
 * Depois de gravar, a proposta abre para anexar o PDF e enviar. Nenhum e-mail sai só por cadastrar.
 * @var array $currentPage @var array<string,string> $proposalForm @var array<string,string> $proposalErrors
 */
use Source\Services\Proposals\ProposalComposer;
use Source\Services\Proposals\ProposalDesk;

$this->layout('default');
$route = erp_url($currentPage['route']);
$value = static fn (string $key): string => (string)($proposalForm[$key] ?? '');
$error = function (string $key) use ($proposalErrors): string {
    return isset($proposalErrors[$key]) ? '<span class="erp-field__error" id="np-' . $key . '-error">' . $this->e($proposalErrors[$key]) . '</span>' : '';
};
$invalid = static fn (string $key): string => isset($proposalErrors[$key]) ? ' aria-invalid="true" aria-describedby="np-' . $key . '-error"' : '';
$required = '<span aria-hidden="true" style="color:var(--erp-danger)">*</span>';
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ' · Comercial',
    'pageTitle' => 'Nova proposta',
    'pageLead' => 'Para quem pediu proposta por telefone, WhatsApp, e-mail, indicação ou numa visita. Depois de gravar, você anexa o PDF e envia.',
    'pageActions' => '<a class="erp-btn" href="' . $this->e($route) . '">' . $icon('chevron-left', 'erp-icon--sm') . 'Voltar às propostas</a>',
]);
?>
<?php if (isset($proposalErrors['form'])): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($proposalErrors['form']) ?></div></div>
<?php elseif ($proposalErrors !== []): ?>
    <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div>Confira os campos marcados.</div></div>
<?php endif; ?>

<section class="erp-card" aria-labelledby="np-title">
    <div class="erp-card__head"><div><h2 id="np-title">Quem pediu a proposta</h2><p>Os campos com * são obrigatórios. A proposta é enviada para o e-mail informado.</p></div></div>
    <form class="erp-form" method="post" action="<?= $this->e($route . '/nova') ?>" data-erp-loading>
        <?= csrf_input() ?>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-6"><label for="np-name">Nome <?= $required ?></label><input class="erp-input" id="np-name" name="name" maxlength="180" required value="<?= $this->e($value('name')) ?>"<?= $invalid('name') ?>><?= $error('name') ?></div>
            <div class="erp-field erp-col-6">
                <label for="np-profile">Perfil <?= $required ?></label>
                <select class="erp-select" id="np-profile" name="profile" required<?= $invalid('profile') ?>>
                    <?php foreach (ProposalDesk::PROFILES as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $value('profile') === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
                </select><?= $error('profile') ?>
            </div>
            <div class="erp-field erp-col-6"><label for="np-email">E-mail <?= $required ?></label><input class="erp-input" type="email" id="np-email" name="email" maxlength="180" required autocomplete="off" value="<?= $this->e($value('email')) ?>"<?= $invalid('email') ?>><?= $error('email') ?></div>
            <div class="erp-field erp-col-6"><label for="np-whatsapp">Telefone ou WhatsApp <?= $required ?></label><input class="erp-input" type="tel" id="np-whatsapp" name="whatsapp" maxlength="30" required placeholder="(31) 99999-0000" value="<?= $this->e($value('whatsapp')) ?>"<?= $invalid('whatsapp') ?>><?= $error('whatsapp') ?></div>
            <div class="erp-field erp-col-6"><label for="np-condominium">Condomínio ou administradora <?= $required ?></label><input class="erp-input" id="np-condominium" name="condominium" maxlength="180" required value="<?= $this->e($value('condominium')) ?>"<?= $invalid('condominium') ?>><?= $error('condominium') ?></div>
            <div class="erp-field erp-col-6"><label for="np-units">Unidades <?= $required ?></label><input class="erp-input" type="number" id="np-units" name="units" min="1" max="100000" required inputmode="numeric" value="<?= $this->e($value('units')) ?>"<?= $invalid('units') ?>><?= $error('units') ?></div>
            <div class="erp-field erp-col-6">
                <label for="np-origin">Como o pedido chegou <?= $required ?></label>
                <select class="erp-select" id="np-origin" name="origin" required<?= $invalid('origin') ?>>
                    <?php foreach (ProposalComposer::ORIGINS as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $value('origin') === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
                </select><?= $error('origin') ?>
            </div>
            <div class="erp-field erp-col-12"><label for="np-message">Necessidade informada</label><textarea class="erp-textarea" id="np-message" name="message" rows="4" maxlength="5000" placeholder="O que a pessoa contou: momento do condomínio, serviços que procura, prazos"><?= $this->e($value('message')) ?></textarea></div>
            <div class="erp-field erp-col-12">
                <label class="erp-check" for="np-requested"><input type="checkbox" id="np-requested" name="requested" value="1" required<?= $value('requested') === '1' ? ' checked' : '' ?><?= $invalid('requested') ?>> A pessoa pediu esta proposta e aceitou receber o nosso contato por e-mail e telefone.</label>
                <span class="erp-field__hint">Sem esse pedido, não cadastre (LGPD). Não marcamos aceite de novidades: só a própria pessoa pode dar, pelo site.</span><?= $error('requested') ?>
            </div>
        </div>
        <div class="erp-form__actions"><a class="erp-btn" href="<?= $this->e($route) ?>">Cancelar</a><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Gravar e preparar a proposta</button></div>
    </form>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
