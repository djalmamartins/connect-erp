<?php
/**
 * Lançamentos patrimoniais (CN-006; ADR 0149, sobre o ADR 0169): partida dobrada entre contas patrimoniais
 * (fundos, PL e outras), sem DRE e sem banco. Lista do período, novo lançamento e estorno pela API
 * /api/v1/patrimonial-entries (erp.js, data-erp-api-forms), com CSRF no cabeçalho; a API confere de novo
 * permissão, escopo, contas e competência fechada.
 * @var array $currentPage @var ?int $patrimonialContext @var array $patrimonialFilters @var ?string $patrimonialError @var ?array $patrimonialList
 * @var list<array> $patrimonialAccounts @var list<array> $patrimonialBanks @var bool $canManagePatrimonial
 */
use Source\Controllers\Erp\Connect\PatrimonialEntryScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;

$this->layout('default');
$route = erp_url($currentPage['route']);
$api = static fn (string $path): string => url('/api/v1' . $path);
$f = $patrimonialFilters;
$list = $patrimonialList;
$link = static fn (string $page, string $fallback): string => erp_url($catalog['pages'][$page]['route'] ?? $fallback);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Movimentos entre contas patrimoniais, como apropriar ou usar o fundo de reserva e ajustar o patrimônio líquido. Não entram na DRE e não mexem no banco.',
    'pageActions' => '',
]);
?>
<div class="erp-stack" data-erp-api-forms data-condominium="<?= (int)($patrimonialContext ?? 0) ?>">
    <?= csrf_input() ?>
    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Período dos lançamentos patrimoniais">
        <div class="erp-field erp-field--compact"><label for="pe-from">De</label><input class="erp-input" type="date" id="pe-from" name="de" value="<?= $this->e($f['de']) ?>" min="2000-01-01" required></div>
        <div class="erp-field erp-field--compact"><label for="pe-to">Até</label><input class="erp-input" type="date" id="pe-to" name="ate" value="<?= $this->e($f['ate']) ?>" min="2000-01-01" required></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
    </form>

    <?php if ($patrimonialContext === null): ?>
        <div class="erp-alert" role="note"><?= $icon('info') ?><div>Para lançar, escolha o condomínio na barra superior. A lista mostra todos os condomínios do seu acesso.</div></div>
    <?php elseif ($canManagePatrimonial): ?>
        <form class="erp-card erp-form" data-access-form data-condo-field data-api="<?= $this->e($api('/patrimonial-entries')) ?>" data-done="Lançamento patrimonial gravado." aria-labelledby="pe-new-title" novalidate>
            <div class="erp-card__head"><div><h2 id="pe-new-title">Novo lançamento</h2><p>Ex.: apropriar o fundo de reserva = débito em 9.2 Resultado do exercício e crédito em 8.2 Fundo de reserva. Usar o fundo numa despesa já lançada é o contrário. Veja o efeito no <a href="<?= $this->e($link('AP-081', '/prestacao-de-contas/balanco-patrimonial')) ?>">Balanço Patrimonial</a>.</p></div></div>
            <?php if ($patrimonialAccounts === []): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div>Nenhuma conta patrimonial analítica disponível. Cadastre os fundos no <a href="<?= $this->e($link('AP-160', '/configuracoes/plano-de-contas')) ?>">plano de contas</a>.</div></div>
            <?php endif; ?>
            <div class="erp-form__grid">
                <div class="erp-field erp-col-3"><label for="pe-date">Data</label><input class="erp-input" type="date" id="pe-date" name="entry_date" value="<?= date('Y-m-d') ?>" min="2000-01-01" max="<?= date('Y-m-d') ?>" required></div>
                <div class="erp-field erp-col-6"><label for="pe-desc">Descrição</label><input class="erp-input" id="pe-desc" name="description" minlength="3" maxlength="255" required placeholder="Ex.: Apropriação do fundo de reserva de outubro"></div>
                <div class="erp-field erp-col-3"><label for="pe-amount">Valor</label><input class="erp-input" id="pe-amount" name="amount_cents" inputmode="decimal" maxlength="18" placeholder="0,00" autocomplete="off" required></div>
                <div class="erp-field erp-col-6"><label for="pe-debit">Conta de débito</label><select class="erp-select" id="pe-debit" name="debit_account_id" required><option value="">Selecione</option><?php foreach ($patrimonialAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select><span class="erp-field__hint">No ativo, o débito aumenta; no passivo e no PL, diminui</span></div>
                <div class="erp-field erp-col-6"><label for="pe-credit">Conta de crédito</label><select class="erp-select" id="pe-credit" name="credit_account_id" required><option value="">Selecione</option><?php foreach ($patrimonialAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select><span class="erp-field__hint">No passivo e no PL, o crédito aumenta; no ativo, diminui</span></div>
                <div class="erp-field erp-col-6"><label for="pe-bank">Conta bancária <span class="erp-muted">(opcional)</span></label><select class="erp-select" id="pe-bank" name="bank_account_id"><option value="">Nenhuma</option><?php foreach ($patrimonialBanks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= $this->e($b['label']) ?></option><?php endforeach; ?></select><span class="erp-field__hint">Só referência: onde está o dinheiro do fundo</span></div>
                <div class="erp-field erp-col-6"><label for="pe-linked">Nº do lançamento de origem <span class="erp-muted">(opcional)</span></label><input class="erp-input" type="number" id="pe-linked" name="linked_entry_id" min="1" step="1" inputmode="numeric"><span class="erp-field__hint">Só referência: a receita ou despesa que originou o movimento</span></div>
            </div>
            <div data-form-error aria-live="assertive"></div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Lançar</button></div>
        </form>
    <?php endif; ?>

    <?php if ($patrimonialError !== null): ?>
        <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível listar</strong><?= $this->e($patrimonialError) ?></div></div>
    <?php elseif ($list !== null): ?>
    <section class="erp-card" aria-labelledby="pe-list-title">
        <div class="erp-card__head"><div><h2 id="pe-list-title">Lançamentos de <?= $this->e(RS::brDate($list['from'])) ?> a <?= $this->e(RS::brDate($list['to'])) ?></h2><p><?= (int)$list['totals']['entries'] ?> lançamento(s)<?= !empty($list['truncated']) ? ' · mostrando os ' . count($list['rows']) . ' mais recentes' : '' ?>. Estorno não apaga: cria o lançamento invertido.</p></div></div>
        <?php if ($list['rows'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('scale') ?></span><strong>Nenhum lançamento patrimonial no período</strong><p>Os fundos mudam pelo saldo inicial e por estes lançamentos.</p></div>
        <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="pe-list-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Data</th><?= $patrimonialContext === null ? '<th scope="col">Condomínio</th>' : '' ?><th scope="col">Descrição</th><th scope="col">Débito</th><th scope="col">Crédito</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th><?= $canManagePatrimonial ? '<th scope="col"><span class="erp-sr-only">Ações</span></th>' : '' ?></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $row): [$label, $tone, $reversible] = Screens::situation($row); ?>
                <tr<?= $row['status'] === 'reversed' ? ' class="erp-muted"' : '' ?>>
                    <td class="erp-nowrap"><?= $this->e(RS::brDate($row['entry_date'])) ?></td>
                    <?php if ($patrimonialContext === null): ?><td><?= $this->e($row['condominium_name']) ?></td><?php endif; ?>
                    <td><?= $this->e($row['description']) ?><?= $row['reversal_reason'] ? '<br><span class="erp-field__hint">Motivo: ' . $this->e((string)$row['reversal_reason']) . '</span>' : '' ?></td>
                    <td><?= $this->e(trim($row['debit']['code'] . ' ' . $row['debit']['name'])) ?></td>
                    <td><?= $this->e(trim($row['credit']['code'] . ' ' . $row['credit']['name'])) ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$row['amount_cents'])) ?></td>
                    <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></td>
                    <?php if ($canManagePatrimonial): ?><td class="erp-nowrap">
                        <?php if ($reversible): ?>
                        <details class="erp-reverse"><summary class="erp-btn erp-btn--sm">Estornar</summary>
                            <form class="erp-inline-form" data-access-form data-api="<?= $this->e($api('/patrimonial-entries/' . (int)$row['id'] . '/reverse')) ?>" data-done="Lançamento estornado." novalidate>
                                <label class="erp-sr-only" for="pe-reason-<?= (int)$row['id'] ?>">Motivo do estorno</label><input class="erp-input" id="pe-reason-<?= (int)$row['id'] ?>" name="reason" minlength="3" maxlength="255" required placeholder="Motivo do estorno">
                                <label class="erp-sr-only" for="pe-rdate-<?= (int)$row['id'] ?>">Data do estorno</label><input class="erp-input" type="date" id="pe-rdate-<?= (int)$row['id'] ?>" name="date" value="<?= date('Y-m-d') ?>" min="<?= $this->e($row['entry_date']) ?>" max="<?= date('Y-m-d') ?>" required>
                                <button type="submit" class="erp-btn erp-btn--danger erp-btn--sm">Confirmar estorno</button>
                                <div data-form-error aria-live="assertive"></div>
                            </form>
                        </details>
                        <?php endif; ?>
                    </td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="<?= $patrimonialContext === null ? 5 : 4 ?>">Total movimentado no período</td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$list['totals']['amount_cents'])) ?></td><td colspan="<?= $canManagePatrimonial ? 2 : 1 ?>"></td></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
