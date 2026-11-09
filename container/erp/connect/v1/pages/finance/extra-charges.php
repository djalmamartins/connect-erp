<?php
/**
 * Multa avulsa (AP-027) e cobranças extras (AP-028), ADR 0057 e 0073, com dados reais: as origens
 * em erp_extra_charges do tipo da página ($charges, no condomínio da barra superior), o detalhe com
 * as contas geradas por unidade (?cobranca=ID) e, com erp.invoices.manage, lançar e cancelar pela API
 * /api/v1/extra-charges (erp.js, data-erp-shortcuts). As contas geradas ficam travadas no cadastro.
 * @var array $currentPage @var string $chargeKind @var ?string $chargeStatus @var array $charges @var ?array $chargeDetail
 * @var list<array> $chargeUnits @var list<array> $chargeAccounts @var bool $shortcutCanManage @var ?int $shortcutContext @var ?string $shortcutError
 */
use Source\Services\Erp\EntryShortcuts;

$this->layout('default');
$route = erp_url($currentPage['route']);
$fine = $chargeKind === 'fine';
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$month = static fn (string $value): string => substr($value, 5, 2) . '/' . substr($value, 0, 4);
$link = static function (array $changes) use ($route, $chargeStatus): string {
    $params = array_filter(array_merge(['situacao' => $chargeStatus], $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $route . ($params === [] ? '' : '?' . http_build_query($params));
};
$rows = $charges['data'] ?? [];
$noun = $fine ? 'multa' : 'cobrança';
$entryStatus = static fn (array $e): array => $e['status'] !== 'active' ? ['Cancelada', 'plain'] : ($e['paid_on'] !== null ? ['Paga', 'success'] : ['Em aberto', 'info']);
$canCreate = $shortcutCanManage && $shortcutContext !== null && $chargeUnits !== [] && $chargeAccounts !== [];

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $chargeDetail !== null ? $chargeDetail['kind_label'] . ' nº ' . (int)$chargeDetail['id'] : $currentPage['name'],
    'pageLead' => $chargeDetail !== null ? $chargeDetail['description'] . ' · ' . ($chargeDetail['condominium_name'] ?? '') : ($fine
        ? 'Multa de uma unidade, com valor fixo e o motivo (regulamento ou ocorrência). Gera uma conta a receber da unidade.'
        : 'Cobrança fora da fatura para uma ou mais unidades: valor fixo por unidade, total dividido igualmente ou rateado pela fração ideal. Gera uma conta a receber por unidade.'),
    'pageActions' => $chargeDetail !== null ? '<a class="erp-btn" href="' . $this->e($link(['cobranca' => null])) . '">Voltar à lista</a>' : '',
]);
?>
<div class="erp-stack" data-erp-shortcuts data-kind="charge" data-route="<?= $this->e($route) ?>">
    <?= csrf_input() ?>
    <?php if ($shortcutError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($shortcutError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à lista</a></div></div>
    <?php endif; ?>

    <?php if ($chargeDetail !== null): $d = $chargeDetail; $active = $d['status'] === 'active'; ?>
        <?php if (!$active): ?>
            <div class="erp-alert erp-alert--danger" role="status"><?= $icon('alert') ?><div><strong>Cancelada em <?= $this->e($date($d['cancelled_at'])) ?></strong><?= $this->e((string)$d['cancel_reason']) ?></div></div>
        <?php endif; ?>
        <div class="erp-grid erp-grid--main">
            <section class="erp-card" aria-labelledby="charge-entries-title">
                <div class="erp-card__head"><div><h2 id="charge-entries-title">Contas geradas</h2><p><?= (int)$d['units_count'] ?> unidade(s) · total <?= $this->e($brl((int)$d['total_cents'])) ?></p></div></div>
                <div class="erp-table-wrap" role="region" aria-labelledby="charge-entries-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Unidade</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($d['entries'] ?? [] as $e): [$statusLabel, $tone] = $entryStatus($e); ?><tr>
                        <td><strong><?= $this->e($e['unit_label']) ?></strong></td>
                        <td class="is-num erp-nowrap"><?= $this->e($brl((int)$e['amount_cents'])) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $statusLabel ?></span><?= $e['paid_on'] !== null ? '<br><span class="erp-muted">em ' . $this->e($date($e['paid_on'])) . '</span>' : '' ?></td>
                        <td class="is-num"><a class="erp-btn erp-btn--sm" href="<?= $this->e(erp_url('/financeiro/lancamentos/' . (int)$e['entry_id'])) ?>" aria-label="Abrir a conta de <?= $this->e($e['unit_label']) ?>">Abrir</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
            <aside class="erp-card" aria-labelledby="charge-facts-title">
                <div class="erp-card__head"><div><h2 id="charge-facts-title">Dados</h2></div></div>
                <dl class="erp-queue-facts">
                    <dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $active ? 'success' : 'plain' ?>"><?= $active ? 'Ativa' : 'Cancelada' ?></span></dd>
                    <dt>Competência</dt><dd><?= $this->e($month($d['competence'])) ?></dd>
                    <dt>Vencimento</dt><dd><?= $this->e($date($d['due_on'])) ?></dd>
                    <dt>Cálculo</dt><dd><?= $this->e(EntryShortcuts::METHODS[$d['method']] ?? $d['method']) ?></dd>
                    <?php if ($d['reason'] !== null && $d['reason'] !== ''): ?><dt>Motivo</dt><dd><?= $this->e((string)$d['reason']) ?></dd><?php endif; ?>
                    <dt>Lançada em</dt><dd><?= $this->e($date($d['created_at'])) ?></dd>
                </dl>
                <?php if ($shortcutCanManage && $active): ?>
                    <button type="button" class="erp-btn erp-btn--danger erp-btn--block" style="margin-top:16px" data-cancel-open data-api="<?= $this->e(url('/api/v1/extra-charges/' . (int)$d['id'] . '/cancel')) ?>" data-label="<?= $this->e($d['kind_label'] . ' nº ' . (int)$d['id'] . ' · ' . $d['description']) ?>">Cancelar <?= $noun ?></button>
                    <p class="erp-field__hint">Só cancela se nenhuma conta estiver paga, em acordo ou com boleto ativo, e com a competência aberta.</p>
                <?php endif; ?>
            </aside>
        </div>
    <?php else: ?>
        <?php if ($shortcutCanManage): ?>
            <?php if ($shortcutContext === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para lançar.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
            <?php elseif (!$canCreate): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong><?= $chargeUnits === [] ? 'Este condomínio não tem unidades ativas.' : 'Falta uma conta de receita.' ?></strong><?= $chargeUnits === [] ? 'Cadastre as unidades em Condomínios › Unidades.' : 'Cadastre uma conta analítica de receita no plano de contas.' ?></div></div>
            <?php else: ?>
                <form class="erp-card erp-form" data-charge-form data-api="<?= $this->e(url('/api/v1/extra-charges')) ?>" data-condominium="<?= (int)$shortcutContext ?>" data-charge-kind="<?= $this->e($chargeKind) ?>" aria-labelledby="charge-form-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="charge-form-title"><?= $fine ? 'Lançar multa' : 'Lançar cobrança extra' ?></h2><p>A conta nasce em aberto, no vencimento escolhido, e entra na cobrança da unidade.</p></div></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-6"><label for="charge-description">Descrição</label><input class="erp-input" id="charge-description" name="description" minlength="3" maxlength="120" required placeholder="<?= $fine ? 'Ex.: Multa por barulho após 22h' : 'Ex.: Fundo de obras da fachada' ?>"></div>
                        <div class="erp-field erp-col-6"><label for="charge-account">Conta de receita</label><select class="erp-select" id="charge-account" name="chart_account_id" required><option value="">Selecione</option><?php foreach ($chargeAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-3"><label for="charge-competence">Competência</label><input class="erp-input" type="month" id="charge-competence" name="competence" value="<?= date('Y-m') ?>" required></div>
                        <div class="erp-field erp-col-3"><label for="charge-due">Vencimento</label><input class="erp-input" type="date" id="charge-due" name="due_on" min="<?= date('Y-m-d') ?>" required></div>
                        <?php if (!$fine): ?>
                            <div class="erp-field erp-col-3"><label for="charge-method">Cálculo</label><select class="erp-select" id="charge-method" name="method" data-charge-method><?php foreach (EntryShortcuts::METHODS as $key => $label): ?><option value="<?= $this->e($key) ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                        <?php endif; ?>
                        <div class="erp-field erp-col-3"><label for="charge-amount" data-charge-amount-label><?= $fine ? 'Valor (R$)' : 'Valor por unidade (R$)' ?></label><input class="erp-input" id="charge-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required></div>
                        <?php if ($fine): ?>
                            <div class="erp-field erp-col-6"><label for="charge-unit">Unidade</label><select class="erp-select" id="charge-unit" name="unit_id" required><option value="">Selecione</option><?php foreach ($chargeUnits as $u): ?><option value="<?= (int)$u['id'] ?>"><?= $this->e($u['label']) ?></option><?php endforeach; ?></select></div>
                            <div class="erp-field erp-col-12"><label for="charge-reason">Motivo</label><textarea class="erp-textarea" id="charge-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: art. 12 do regulamento interno, ocorrência nº 45"></textarea></div>
                        <?php else: ?>
                            <fieldset class="erp-field erp-col-12 erp-charge-units" data-charge-units>
                                <legend>Unidades <span class="erp-muted" data-charge-units-count>(<?= count($chargeUnits) ?> de <?= count($chargeUnits) ?>)</span></legend>
                                <label class="erp-check"><input type="checkbox" data-charge-units-all checked> Todas as unidades</label>
                                <div class="erp-charge-units__grid"><?php foreach ($chargeUnits as $u): ?><label class="erp-check"><input type="checkbox" name="unit_ids" value="<?= (int)$u['id'] ?>" checked> <?= $this->e($u['label']) ?></label><?php endforeach; ?></div>
                            </fieldset>
                        <?php endif; ?>
                    </div>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?><?= $fine ? 'Lançar multa' : 'Lançar cobrança' ?></button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <section class="erp-card" aria-labelledby="charge-list-title">
            <div class="erp-card__head"><div><h2 id="charge-list-title"><?= $fine ? 'Multas lançadas' : 'Cobranças lançadas' ?></h2><p><?= (int)($charges['total'] ?? 0) ?> lançamento(s)<?= $shortcutContext === null ? ' em todos os condomínios' : '' ?><?= ($charges['total'] ?? 0) >= 200 ? ', os 200 mais recentes' : '' ?>.</p></div></div>
            <nav class="erp-disk-folders" aria-label="Situação">
                <?php foreach (['' => 'Todas', 'active' => 'Ativas', 'cancelled' => 'Canceladas'] as $key => $label): $key = $key === '' ? null : $key; ?>
                    <a class="erp-disk-folder<?= $chargeStatus === $key ? ' is-active' : '' ?>" href="<?= $this->e($link(['situacao' => $key])) ?>"<?= $chargeStatus === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('receipt') ?></span><strong><?= $fine ? 'Nenhuma multa' : 'Nenhuma cobrança extra' ?><?= $chargeStatus !== null ? ' nesta situação' : '' ?></strong><p><?= $canCreate ? 'Use o formulário acima para lançar a primeira.' : 'Os lançamentos aparecem aqui.' ?></p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="charge-list-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Vencimento</th><th scope="col">Descrição</th><th scope="col" class="is-num">Total</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): $id = (int)$r['id']; $active = $r['status'] === 'active'; $label = $r['kind_label'] . ' nº ' . $id . ' · ' . $r['description']; ?><tr>
                        <td class="erp-nowrap"><?= $this->e($date($r['due_on'])) ?><br><span class="erp-muted">comp. <?= $this->e($month($r['competence'])) ?></span></td>
                        <td><strong><?= $this->e($r['description']) ?></strong> <span class="erp-muted">nº <?= $id ?></span><br><span class="erp-muted"><?= $this->e($fine ? (string)$r['reason'] : (EntryShortcuts::METHODS[$r['method']] ?? $r['method']) . ' · ' . (int)$r['units_count'] . ' unidade(s)') ?><?= $shortcutContext === null ? ' · ' . $this->e((string)$r['condominium_name']) : '' ?></span></td>
                        <td class="is-num erp-nowrap"><?= $this->e($brl((int)$r['total_cents'])) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $active ? 'success' : 'plain' ?>"><?= $active ? 'Ativa' : 'Cancelada' ?></span></td>
                        <td class="is-num"><div class="erp-table__actions">
                            <a class="erp-btn erp-btn--sm" href="<?= $this->e($link(['cobranca' => $id])) ?>" aria-label="Ver as contas de <?= $this->e($label) ?>">Contas</a>
                            <?php if ($shortcutCanManage && $active): ?><button type="button" class="erp-btn erp-btn--sm" data-cancel-open data-api="<?= $this->e(url('/api/v1/extra-charges/' . $id . '/cancel')) ?>" data-label="<?= $this->e($label) ?>" aria-label="Cancelar <?= $this->e($label) ?>">Cancelar</button><?php endif; ?>
                        </div></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php $this->insert('components/cards/shortcut-cancel', ['canManage' => $shortcutCanManage, 'title' => 'Cancelar ' . $noun, 'hint' => 'As contas geradas são canceladas juntas. O lançamento continua registrado, com o motivo.']); ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
