<?php
/**
 * Boletos (AP-035, ADR 0035 e 0038) com dados reais: lista ($bankCharges, de Panel::bankingData, no
 * contexto da barra superior), filtro por situação e as ações pela API /api/v1/bank-charges (erp.js,
 * data-erp-bank-charges): emitir de uma conta a receber em aberto, consultar no banco, cancelar e
 * copiar a linha digitável ou o Pix e abrir o PDF (Inter, ADR 0156). Só ambientes de teste: nada aqui é pagável.
 * @var array $currentPage @var array $bankCharges @var bool $canManageCharges @var list<array> $condoOptions
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$filters = ['situacao' => is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : ''];
$query = static function (array $changes) use ($filters): string {
    $params = array_filter(array_merge($filters, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$tones = ['pending' => 'warning', 'registered' => 'info', 'rejected' => 'danger', 'paid' => 'success', 'cancelled' => 'plain'];
$statuses = \Source\Services\Erp\Banking\BankCharges::STATUSES;
$context = \Source\Support\Erp\CondominiumContext::id();
$condoNames = [];
foreach ($condoOptions ?? [] as $condo) {
    $condoNames[(int)$condo['id']] = (string)$condo['name'];
}
$rows = $bankCharges['data'] ?? [];
$canManageCharges ??= false;

ob_start(); ?>
    <?php if ($canManageCharges): ?><button type="button" class="erp-btn erp-btn--primary" data-erp-modal-open="charge-issue"><?= $icon('plus', 'erp-icon--sm') ?>Emitir boleto</button><?php endif; ?>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Boletos das contas a receber das unidades: registro no banco, consulta, cancelamento e baixa pelo arquivo de retorno ou pelo aviso do banco.',
    'pageActions' => $actions,
]);
?>
<div class="erp-alert erp-alert--warning" role="note"><div><strong>Ambiente de testes.</strong> Só bancos de teste estão ligados: o sandbox interno (linha digitável começa com SANDBOX) e o sandbox do Banco Inter. Nenhum destes boletos pode ser pago e nenhum banco real recebe estes títulos.</div></div>
<div class="erp-stack" data-erp-bank-charges data-api="<?= $this->e(url('/api/v1/bank-charges')) ?>" data-entries-api="<?= $this->e(url('/api/v1/entries')) ?>" data-accounts-api="<?= $this->e(url('/api/v1/bank-accounts')) ?>">
    <?= csrf_input() ?>
    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtrar boletos">
        <div class="erp-field erp-field--compact"><label for="chg-status">Situação</label><select class="erp-select" id="chg-status" name="situacao"><option value="">Todas</option>
            <?php foreach ($statuses as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $filters['situacao'] === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
        <?php if ($filters['situacao'] !== ''): ?><div class="erp-field erp-field--actions"><a class="erp-btn erp-btn--ghost" href="<?= $this->e($route) ?>">Limpar</a></div><?php endif; ?>
    </form>

    <section class="erp-card" aria-labelledby="chg-list">
        <div class="erp-card__head"><div><h2 id="chg-list">Boletos</h2><p><?= (int)($bankCharges['total'] ?? 0) ?> boleto(s)<?= $context === null ? ' em todos os condomínios do seu escopo' : '' ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('receipt') ?></span><strong>Nenhum boleto encontrado</strong><p><?= $canManageCharges ? 'Use "Emitir boleto" para registrar uma conta a receber em aberto no banco.' : 'Ainda não há boletos com estes filtros.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="chg-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Nosso número</th><th>Vencimento</th><th class="is-num">Valor</th><th>Linha digitável</th><th>Situação</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $charge): $status = (string)$charge['status']; $open = in_array($status, ['pending', 'registered'], true); ?>
                    <tr>
                        <td><strong><?= $this->e((string)$charge['our_number']) ?></strong><small class="erp-muted"><?= $context === null ? $this->e($condoNames[(int)$charge['condominium_id']] ?? ('Condomínio nº ' . (int)$charge['condominium_id'])) . ' · ' : '' ?>lançamento nº <?= (int)$charge['entry_id'] ?><?= $charge['adapter'] === 'inter' ? ' · Banco Inter' . (!empty($charge['bank_our_number']) ? ' nº ' . $this->e((string)$charge['bank_our_number']) : '') : '' ?></small></td>
                        <td><?= $this->e($date((string)$charge['due_on'])) ?><?php if ($status === 'paid'): ?><small class="erp-muted">pago <?= $this->e($date($charge['paid_on'])) ?></small><?php endif; ?></td>
                        <td class="is-num"><?= $brl((int)$charge['amount_cents']) ?><?php if ($status === 'paid' && $charge['paid_cents'] !== null && (int)$charge['paid_cents'] !== (int)$charge['amount_cents']): ?><small class="erp-muted">pago <?= $brl((int)$charge['paid_cents']) ?></small><?php endif; ?></td>
                        <td class="erp-charge-line"><?php if (!empty($charge['digitable_line'])): ?><code><?= $this->e((string)$charge['digitable_line']) ?></code><?php else: ?><span class="erp-muted">—</span><?php endif; ?><?php if (!empty($charge['last_error'])): ?><small class="erp-muted"><?= $this->e((string)$charge['last_error']) ?></small><?php endif; ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tones[$status] ?? 'plain' ?>"><?= $this->e((string)$charge['status_label']) ?></span></td>
                        <td class="is-num erp-invoice-actions"><div class="erp-table__actions">
                            <?php if (!empty($charge['digitable_line']) && $status === 'registered'): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-charge-copy="<?= $this->e((string)$charge['digitable_line']) ?>" title="Copiar a linha digitável"><?= $icon('clipboard', 'erp-icon--sm') ?><span class="erp-sr-only">Copiar linha digitável</span></button><?php endif; ?>
                            <?php if (!empty($charge['pix_copy_paste']) && $status === 'registered'): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-charge-copy="<?= $this->e((string)$charge['pix_copy_paste']) ?>" data-copy-label="o Pix copia e cola" title="Copiar o Pix copia e cola">Pix<span class="erp-sr-only"> copia e cola</span></button><?php endif; ?>
                            <?php if (!empty($charge['pdf_available'])): ?><a class="erp-btn erp-btn--sm erp-btn--ghost" href="<?= $this->e(url('/api/v1/bank-charges/' . (int)$charge['id'] . '/pdf')) ?>" target="_blank" rel="noopener" title="Abrir o PDF do boleto">PDF</a><?php endif; ?>
                            <?php if ($canManageCharges && $open): ?><button type="button" class="erp-btn erp-btn--sm" data-charge-refresh="<?= (int)$charge['id'] ?>">Consultar</button><?php endif; ?>
                            <?php if ($canManageCharges && $open): ?><button type="button" class="erp-btn erp-btn--sm" data-charge-cancel="<?= (int)$charge['id'] ?>" data-label="<?= $this->e((string)$charge['our_number']) ?>">Cancelar</button><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (($bankCharges['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$bankCharges['page'] ?> de <?= (int)$bankCharges['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($bankCharges['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $bankCharges['page'] - 1])) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($bankCharges['page'] < $bankCharges['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $bankCharges['page'] + 1])) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($canManageCharges): ?>
    <dialog class="erp-modal erp-modal--wide" id="charge-issue" aria-labelledby="charge-issue-title">
        <form data-charge-issue-form>
            <div class="erp-modal__head"><h2 id="charge-issue-title">Emitir boleto</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p class="erp-muted">Escolha uma conta a receber em aberto e a conta bancária de cobrança. O valor e o vencimento vêm do lançamento. Pedir de novo para o mesmo lançamento devolve o mesmo boleto.</p>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="chg-condo">Condomínio</label>
                        <?php if ($context !== null): ?>
                            <input type="hidden" name="condominium_id" value="<?= (int)$context ?>"><input class="erp-input" id="chg-condo" value="<?= $this->e((string)(\Source\Support\Erp\CondominiumContext::current()['name'] ?? '')) ?>" readonly>
                        <?php else: ?>
                            <select class="erp-select" id="chg-condo" name="condominium_id" required><option value="">Escolha</option><?php foreach ($condoOptions ?? [] as $condo): ?><option value="<?= (int)$condo['id'] ?>"><?= $this->e((string)$condo['name']) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                    </div>
                    <div class="erp-field erp-col-6"><label for="chg-account">Conta bancária</label><select class="erp-select" id="chg-account" name="bank_account_id" required data-charge-accounts><option value="">Escolha o condomínio</option></select></div>
                    <div class="erp-field erp-col-12"><label for="chg-search">Buscar conta a receber</label><input class="erp-input" type="search" id="chg-search" data-charge-search placeholder="Descrição ou competência (ex.: 2026-11)" maxlength="100"></div>
                    <div class="erp-field erp-col-12"><label for="chg-entry">Conta a receber em aberto</label><select class="erp-select" id="chg-entry" name="entry_id" required data-charge-entries><option value="">Escolha o condomínio</option></select></div>
                </div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Emitir</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
