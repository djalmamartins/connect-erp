<?php
/**
 * Faturas da cobrança (AP-032, ADR 0033) com dados reais: lista ($invoices, de Panel::invoiceData,
 * no contexto da barra superior), filtros por competência e situação, e as ações pela API
 * /api/v1/invoices (erp.js, data-erp-invoices): preparar, ver e ajustar os itens por unidade
 * (só em preparação), emitir e cancelar com motivo. A API valida permissão, escopo e CSRF de novo.
 * @var array $currentPage @var array $invoices @var bool $canManageInvoices @var list<array> $condoOptions
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$filters = ['competencia' => is_string($_GET['competencia'] ?? null) ? $_GET['competencia'] : '', 'situacao' => is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : ''];
$query = static function (array $changes) use ($filters): string {
    $params = array_filter(array_merge($filters, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$month = static fn (string $value): string => substr($value, 5, 2) . '/' . substr($value, 0, 4);
$tones = ['draft' => 'warning', 'issued' => 'success', 'cancelled' => 'plain'];
$statuses = \Source\Services\Erp\Invoices::STATUSES;
$methods = \Source\Services\Erp\Invoices::METHODS;
$context = \Source\Support\Erp\CondominiumContext::id();
$apiBase = url('/api/v1/invoices');
$rows = $invoices['data'] ?? [];
$canManageInvoices ??= false;

ob_start(); ?>
    <?php if ($canManageInvoices): ?><button type="button" class="erp-btn erp-btn--primary" data-erp-modal-open="invoice-prepare"><?= $icon('plus', 'erp-icon--sm') ?>Preparar fatura</button><?php endif; ?>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Cobrança de cada tipo de fatura das unidades ativas do condomínio. Prepare, confira os valores por unidade e emita para gerar as contas a receber.',
    'pageActions' => $actions,
]);
?>
<div data-erp-invoices data-api="<?= $this->e($apiBase) ?>" data-types-api="<?= $this->e(url('/api/v1/invoice-types')) ?>" data-can-manage="<?= $canManageInvoices ? '1' : '0' ?>">
    <?= csrf_input() ?>
    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtrar faturas">
        <div class="erp-field erp-field--compact"><label for="inv-month">Competência</label><input class="erp-input" type="month" id="inv-month" name="competencia" value="<?= $this->e($filters['competencia']) ?>"></div>
        <div class="erp-field erp-field--compact"><label for="inv-status">Situação</label><select class="erp-select" id="inv-status" name="situacao"><option value="">Todas</option>
            <?php foreach ($statuses as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $filters['situacao'] === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
        <?php if ($filters['competencia'] !== '' || $filters['situacao'] !== ''): ?><div class="erp-field erp-field--actions"><a class="erp-btn erp-btn--ghost" href="<?= $this->e($route) ?>">Limpar</a></div><?php endif; ?>
    </form>

    <section class="erp-card" aria-labelledby="inv-list">
        <div class="erp-card__head"><div><h2 id="inv-list">Faturas</h2><p><?= (int)($invoices['total'] ?? 0) ?> fatura(s)<?= $context === null ? ' em todos os condomínios do seu escopo' : '' ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('receipt') ?></span><strong>Nenhuma fatura encontrada</strong><p><?= $canManageInvoices ? 'Use "Preparar fatura" para cobrar um tipo de fatura das unidades de um condomínio.' : 'Ainda não há faturas com estes filtros.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="inv-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Competência</th><?php if ($context === null): ?><th>Condomínio</th><?php endif; ?><th>Tipo</th><th class="is-num">Valor</th><th>Situação</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $invoice): $status = (string)$invoice['status']; ?>
                    <tr>
                        <td><?= $this->e($month((string)$invoice['competence'])) ?><small class="erp-muted">vence <?= $this->e($date((string)$invoice['due_on'])) ?></small></td>
                        <?php if ($context === null): ?><td><?= $this->e((string)$invoice['condominium_name']) ?></td><?php endif; ?>
                        <td><button type="button" class="erp-invoice-link" data-invoice-open="<?= (int)$invoice['id'] ?>" title="Ver os itens por unidade"><?= $this->e((string)$invoice['type_name']) ?></button><?php if ($status === 'cancelled' && !empty($invoice['cancel_reason'])): ?><small class="erp-muted">Cancelada: <?= $this->e((string)$invoice['cancel_reason']) ?></small><?php endif; ?></td>
                        <td class="is-num"><?= $brl((int)$invoice['amount_cents']) ?><small class="erp-muted"><?= (int)$invoice['units_count'] ?> unidade(s)</small></td>
                        <td><span class="erp-badge erp-badge--<?= $tones[$status] ?? 'plain' ?>"><?= $this->e((string)$invoice['status_label']) ?></span></td>
                        <td class="is-num erp-invoice-actions"><div class="erp-table__actions">
                            <?php if ($canManageInvoices && $status === 'draft'): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--primary" data-invoice-issue="<?= (int)$invoice['id'] ?>" data-label="<?= $this->e($invoice['type_name'] . ' ' . $month((string)$invoice['competence'])) ?>">Emitir</button><?php endif; ?>
                            <?php if ($canManageInvoices && $status !== 'cancelled'): ?><button type="button" class="erp-btn erp-btn--sm" data-invoice-cancel="<?= (int)$invoice['id'] ?>" data-label="<?= $this->e($invoice['type_name'] . ' ' . $month((string)$invoice['competence'])) ?>">Cancelar</button><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (($invoices['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$invoices['page'] ?> de <?= (int)$invoices['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($invoices['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $invoices['page'] - 1])) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($invoices['page'] < $invoices['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $invoices['page'] + 1])) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <dialog class="erp-modal erp-modal--wide" id="invoice-items" aria-labelledby="invoice-items-title">
        <div class="erp-modal__head"><h2 id="invoice-items-title">Itens da fatura</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
        <div class="erp-modal__body" data-invoice-items aria-live="polite"><p class="erp-muted">Carregando…</p></div>
        <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Fechar</button></div>
    </dialog>

    <?php if ($canManageInvoices): ?>
    <dialog class="erp-modal" id="invoice-cancel" aria-labelledby="invoice-cancel-title">
        <form data-invoice-cancel-form>
            <div class="erp-modal__head"><h2 id="invoice-cancel-title">Cancelar fatura</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p data-invoice-cancel-label></p>
                <p class="erp-muted">Se a fatura já foi emitida, as contas a receber das unidades são canceladas junto. Não é possível cancelar se alguma já foi paga ou se a competência está fechada.</p>
                <div class="erp-field"><label for="inv-cancel-reason">Motivo do cancelamento</label><textarea class="erp-textarea" id="inv-cancel-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: valor do rateio foi alterado em assembleia"></textarea></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger">Cancelar fatura</button></div>
        </form>
    </dialog>

    <dialog class="erp-modal erp-modal--wide" id="invoice-prepare" aria-labelledby="invoice-prepare-title">
        <form data-invoice-prepare-form>
            <div class="erp-modal__head"><h2 id="invoice-prepare-title">Preparar fatura</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p class="erp-muted">Cria um item para cada unidade ativa do condomínio. Você confere e ajusta os valores antes de emitir.</p>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="inv-condo">Condomínio</label>
                        <?php if ($context !== null): ?>
                            <input type="hidden" name="condominium_id" value="<?= (int)$context ?>"><input class="erp-input" id="inv-condo" value="<?= $this->e((string)(\Source\Support\Erp\CondominiumContext::current()['name'] ?? '')) ?>" readonly>
                        <?php else: ?>
                            <select class="erp-select" id="inv-condo" name="condominium_id" required><option value="">Escolha</option><?php foreach ($condoOptions ?? [] as $condo): ?><option value="<?= (int)$condo['id'] ?>"><?= $this->e((string)$condo['name']) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                    </div>
                    <div class="erp-field erp-col-6"><label for="inv-type">Tipo de fatura</label><select class="erp-select" id="inv-type" name="invoice_type_id" required data-invoice-types><option value="">Escolha o condomínio</option></select></div>
                    <div class="erp-field erp-col-4"><label for="inv-competence">Competência</label><input class="erp-input" type="month" id="inv-competence" name="competence" value="<?= $this->e($filters['competencia'] !== '' ? $filters['competencia'] : date('Y-m')) ?>" required></div>
                    <div class="erp-field erp-col-4"><label for="inv-due">Vencimento</label><input class="erp-input" type="date" id="inv-due" name="due_on" required></div>
                    <div class="erp-field erp-col-4"><label for="inv-method">Cálculo</label><select class="erp-select" id="inv-method" name="method"><?php foreach ($methods as $key => $label): ?><option value="<?= $this->e($key) ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="erp-field erp-col-6"><label for="inv-amount" data-invoice-amount-label>Valor por unidade (R$)</label><input class="erp-input" id="inv-amount" name="amount_cents" inputmode="decimal" placeholder="Vazio = valor padrão do tipo" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$"></div>
                    <div class="erp-field erp-col-6"><label for="inv-notes">Observações</label><input class="erp-input" id="inv-notes" name="notes" maxlength="255"></div>
                </div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Preparar</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
