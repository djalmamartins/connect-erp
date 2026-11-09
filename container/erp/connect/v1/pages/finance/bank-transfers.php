<?php
/**
 * Outros lançamentos (AP-029, ADR 0057 e 0073) = transferência entre contas bancárias do mesmo
 * condomínio, com dados reais: as transferências do condomínio da barra superior ($transfers) e,
 * com erp.entries.manage, registrar e cancelar pela API /api/v1/bank-transfers (erp.js,
 * data-erp-shortcuts). Não é receita nem despesa: entra só nos saldos e na conciliação.
 * @var array $currentPage @var ?string $transferStatus @var array $transfers @var list<array> $transferAccounts
 * @var bool $shortcutCanManage @var ?int $shortcutContext
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$link = static fn (?string $status): string => $route . ($status !== null ? '?' . http_build_query(['situacao' => $status]) : '');
$rows = $transfers['data'] ?? [];
$canCreate = $shortcutCanManage && $shortcutContext !== null && count($transferAccounts) >= 2;

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Transferência entre contas bancárias do mesmo condomínio, como da conta corrente para a poupança. Não é receita nem despesa: muda só o saldo de cada conta.',
    'pageActions' => '',
]);
?>
<div class="erp-stack" data-erp-shortcuts data-kind="transfer" data-route="<?= $this->e($route) ?>">
    <?= csrf_input() ?>
    <?php if ($shortcutCanManage): ?>
        <?php if ($shortcutContext === null): ?>
            <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para registrar.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
        <?php elseif (!$canCreate): ?>
            <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>O condomínio precisa de duas contas bancárias ativas.</strong>Cadastre as contas em Financeiro › Contas bancárias.</div></div>
        <?php else: ?>
            <form class="erp-card erp-form" data-transfer-form data-api="<?= $this->e(url('/api/v1/bank-transfers')) ?>" data-condominium="<?= (int)$shortcutContext ?>" aria-labelledby="transfer-form-title" novalidate>
                <div class="erp-card__head"><div><h2 id="transfer-form-title">Registrar transferência</h2><p>Sai de uma conta e entra na outra na mesma data; o total do condomínio não muda.</p></div></div>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="transfer-from">Sai da conta</label><select class="erp-select" id="transfer-from" name="from_bank_account_id" required><option value="">Selecione</option><?php foreach ($transferAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                    <div class="erp-field erp-col-6"><label for="transfer-to">Entra na conta</label><select class="erp-select" id="transfer-to" name="to_bank_account_id" required><option value="">Selecione</option><?php foreach ($transferAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                    <div class="erp-field erp-col-3"><label for="transfer-amount">Valor (R$)</label><input class="erp-input" id="transfer-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required></div>
                    <div class="erp-field erp-col-3"><label for="transfer-date">Data</label><input class="erp-input" type="date" id="transfer-date" name="transferred_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="erp-field erp-col-6"><label for="transfer-description">Descrição</label><input class="erp-input" id="transfer-description" name="description" minlength="3" maxlength="160" required placeholder="Ex.: Aplicação do fundo de reserva"></div>
                </div>
                <div data-form-error aria-live="assertive"></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Registrar</button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <section class="erp-card" aria-labelledby="transfer-list-title">
        <div class="erp-card__head"><div><h2 id="transfer-list-title">Transferências</h2><p><?= (int)($transfers['total'] ?? 0) ?> transferência(s)<?= $shortcutContext === null ? ' em todos os condomínios' : '' ?><?= ($transfers['total'] ?? 0) >= 200 ? ', as 200 mais recentes' : '' ?>.</p></div></div>
        <nav class="erp-disk-folders" aria-label="Situação">
            <?php foreach (['' => 'Todas', 'active' => 'Ativas', 'cancelled' => 'Canceladas'] as $key => $label): $key = $key === '' ? null : $key; ?>
                <a class="erp-disk-folder<?= $transferStatus === $key ? ' is-active' : '' ?>" href="<?= $this->e($link($key)) ?>"<?= $transferStatus === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhuma transferência<?= $transferStatus !== null ? ' nesta situação' : '' ?></strong><p><?= $canCreate ? 'Use o formulário acima para registrar a primeira.' : 'As transferências registradas aparecem aqui.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="transfer-list-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Data</th><th scope="col">De → para</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $r): $id = (int)$r['id']; $active = $r['status'] === 'active'; $label = 'Transferência nº ' . $id . ' · ' . $r['description']; ?><tr>
                    <td class="erp-nowrap"><?= $this->e($date($r['transferred_on'])) ?></td>
                    <td><strong><?= $this->e($r['description']) ?></strong> <span class="erp-muted">nº <?= $id ?></span><br><span class="erp-muted"><?= $this->e($r['from_name']) ?> <span aria-hidden="true">→</span><span class="erp-sr-only"> para </span> <?= $this->e($r['to_name']) ?><?= $shortcutContext === null ? ' · ' . $this->e((string)$r['condominium_name']) : '' ?></span><?php if (!$active && $r['cancel_reason']): ?><br><span class="erp-muted">Cancelada: <?= $this->e((string)$r['cancel_reason']) ?></span><?php endif; ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e($brl((int)$r['amount_cents'])) ?></td>
                    <td><span class="erp-badge erp-badge--<?= $active ? 'success' : 'plain' ?>"><?= $active ? 'Ativa' : 'Cancelada' ?></span></td>
                    <td class="is-num"><?php if ($shortcutCanManage && $active): ?><button type="button" class="erp-btn erp-btn--sm" data-cancel-open data-api="<?= $this->e(url('/api/v1/bank-transfers/' . $id . '/cancel')) ?>" data-label="<?= $this->e($label) ?>" aria-label="Cancelar <?= $this->e($label) ?>">Cancelar</button><?php else: ?><span class="erp-muted">—</span><?php endif; ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php $this->insert('components/cards/shortcut-cancel', ['canManage' => $shortcutCanManage, 'title' => 'Cancelar transferência', 'hint' => 'A transferência sai dos saldos das duas contas e continua registrada, com o motivo.']); ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
