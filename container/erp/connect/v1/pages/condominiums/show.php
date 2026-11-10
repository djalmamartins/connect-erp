<?php
/**
 * Visão geral do condomínio (ADR 0151): o menu Condomínios abre esta tela direto quando há um
 * condomínio na barra superior. Dados completos do cadastro (CondominiumRegistry::find), passos
 * do cadastro (CondominiumSetup) e atalhos para os cadastros ligados a ele. Somente leitura.
 *
 * @var array<string, mixed> $condominium  colunas de CondominiumValidator::FIELDS + id
 * @var array{condominium:array,steps:list<array>,current:null} $setupSteps
 */
$this->layout('default');
$c = $condominium;
$id = (int)$c['id'];
$active = ($c['status'] ?? 'active') === 'active';
$text = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (string)$value;
$zip = preg_replace('/\D+/', '', (string)($c['zip_code'] ?? ''));
$address = trim(implode(', ', array_filter([
    trim(($c['street'] ?? '') . (($c['number'] ?? '') !== '' ? ', ' . $c['number'] : '')),
    $c['complement'] ?? '',
    $c['district'] ?? '',
    trim(($c['city'] ?? '') . (($c['state'] ?? '') !== '' ? '/' . $c['state'] : '')),
], static fn ($part): bool => trim((string)$part) !== '')));
$contract = $c['contract_start_date'] ?? null;
$phoneDigits = preg_replace('/\D+/', '', (string)($c['phone'] ?? ''));
$phone = match (strlen($phoneDigits)) {
    11 => sprintf('(%s) %s-%s', substr($phoneDigits, 0, 2), substr($phoneDigits, 2, 5), substr($phoneDigits, 7)),
    10 => sprintf('(%s) %s-%s', substr($phoneDigits, 0, 2), substr($phoneDigits, 2, 4), substr($phoneDigits, 6)),
    default => $text($c['phone'] ?? null),
};
ob_start(); ?>
    <a class="erp-btn" href="<?= erp_context_url('/condominios', null) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Todos os condomínios</a>
    <a class="erp-btn" href="<?= erp_url('/condominios/painel-do-condominio') ?>"><?= $icon('chart', 'erp-icon--sm') ?>Painel</a>
    <a class="erp-btn" href="<?= erp_url('/condominios/' . $id . '/checklist') ?>"><?= $icon('clipboard', 'erp-icon--sm') ?>Checklist de chegada</a>
    <?php if ($user->can('erp.condominiums.manage')): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url('/condominios/' . $id . '/editar') ?>"><?= $icon('edit', 'erp-icon--sm') ?>Editar</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínio',
    'pageTitle' => (string)$c['name'],
    'pageLead' => 'Visão geral do condomínio: cadastro, o que já foi feito e atalhos para os cadastros dele.',
    'pageActions' => $actions,
]);
// Cadastros ligados ao condomínio: cada link abre a lista real já filtrada por ele.
$related = [];
foreach (\Source\Services\Erp\Registry\Definitions::all() as $definition) {
    if ($definition['scope'] === 'none' || !$user->can($definition['permission'] . '.view')) {
        continue;
    }
    $related[] = $definition;
}
?>
<?php if (($setupSteps['steps'] ?? []) !== []) { $this->insert('components/layout/setup-guide', ['guide' => $setupSteps, 'inline' => true]); } ?>

<div class="erp-grid erp-grid--main">
    <div class="erp-stack">
        <section class="erp-card" aria-labelledby="condo-data-title">
            <div class="erp-card__head"><div><h2 id="condo-data-title">Identificação</h2><p>Cadastro do condomínio (AP-004)</p></div>
                <span class="erp-badge erp-badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Ativo' : 'Inativo' ?></span></div>
            <dl class="erp-detail-grid">
                <div><dt>CNPJ</dt><dd><?= $this->e(format_cnpj($c['document'] ?? null)) ?></dd></div>
                <div><dt>Nome</dt><dd><?= $this->e($text($c['name'])) ?></dd></div>
                <div><dt>Razão social</dt><dd><?= $this->e($text($c['legal_name'] ?? null)) ?></dd></div>
                <div><dt>Código interno</dt><dd><?= $id ?></dd></div>
            </dl>
        </section>

        <section class="erp-card" aria-labelledby="condo-address-title">
            <div class="erp-card__head"><div><h2 id="condo-address-title">Endereço e contato</h2></div></div>
            <dl class="erp-detail-grid">
                <div><dt>Endereço</dt><dd><?= $this->e($text($address)) ?></dd></div>
                <div><dt>CEP</dt><dd><?= $this->e(strlen($zip) === 8 ? substr($zip, 0, 5) . '-' . substr($zip, 5) : $text($c['zip_code'] ?? null)) ?></dd></div>
                <div><dt>Telefone</dt><dd><?= $this->e($phone) ?></dd></div>
                <div><dt>E-mail</dt><dd><?= $this->e($text($c['email'] ?? null)) ?></dd></div>
            </dl>
        </section>

        <section class="erp-card" aria-labelledby="condo-contract-title">
            <div class="erp-card__head"><div><h2 id="condo-contract-title">Estrutura, contrato e banco</h2></div></div>
            <dl class="erp-detail-grid">
                <div><dt>Blocos</dt><dd><?= $this->e($text($c['blocks_count'] ?? null)) ?></dd></div>
                <div><dt>Unidades (informadas no cadastro)</dt><dd><?= $this->e($text($c['units_count'] ?? null)) ?></dd></div>
                <div><dt>Início do contrato</dt><dd><?= $this->e($contract ? date('d/m/Y', (int)strtotime((string)$contract)) : '—') ?></dd></div>
                <div><dt>Vencimento da taxa</dt><dd><?= $this->e(($c['fee_due_day'] ?? null) ? 'Dia ' . (int)$c['fee_due_day'] : '—') ?></dd></div>
                <div><dt>Banco</dt><dd><?= $this->e($text($c['bank_code'] ?? null)) ?></dd></div>
                <div><dt>Agência e conta</dt><dd><?= $this->e(($c['bank_branch'] ?? '') !== '' || ($c['bank_account'] ?? '') !== '' ? trim(($c['bank_branch'] ?? '') . ' / ' . ($c['bank_account'] ?? ''), ' /') : '—') ?></dd></div>
            </dl>
        </section>
    </div>

    <aside class="erp-card" aria-labelledby="condo-related-title">
        <div class="erp-card__head"><div><h2 id="condo-related-title">Cadastros do condomínio</h2><p>Abre a lista já filtrada por este condomínio</p></div></div>
        <?php if ($related === []): ?>
            <p class="erp-field__hint">Você não tem acesso a nenhum cadastro deste condomínio.</p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($related as $definition): ?>
                    <li><span class="erp-list__icon"><?= $icon('clipboard', 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><a href="<?= erp_url($definition['route']) ?>?condominio=<?= $id ?>"><strong><?= $this->e($definition['label']) ?></strong></a></div></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>
