<?php
/**
 * Lançar desconto (AP-026, ADR 0057 e 0073) com dados reais: as contas a receber em aberto do
 * condomínio da barra superior ($discountEntries, pela listagem do motor) e, por conta, "Dar desconto"
 * por POST /api/v1/entries/{id}/discount (erp.js, data-erp-shortcuts). A API confere de novo a
 * permissão, o escopo, o acordo, o boleto ativo e a competência fechada, e grava a trilha.
 * @var array $currentPage @var array $discountEntries @var array<int,string> $discountUnits
 * @var string $discountQuery @var bool $shortcutCanManage @var ?int $shortcutContext
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$rows = $discountEntries['data'] ?? [];
$today = date('Y-m-d');
$page = static fn (int $n): string => $route . '?' . http_build_query(array_filter(['q' => $discountQuery, 'pagina' => $n > 1 ? $n : null]));

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Desconto numa conta a receber em aberto. O valor da conta diminui e fica registrado o antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento.',
    'pageActions' => '<a class="erp-btn" href="' . $this->e(erp_url('/financeiro/contas-a-receber')) . '">' . $icon('wallet', 'erp-icon--sm') . 'Contas a receber</a>',
]);
?>
<div class="erp-stack" data-erp-shortcuts data-kind="discount">
    <?= csrf_input() ?>
    <section class="erp-card" aria-labelledby="discount-title">
        <div class="erp-card__head">
            <div><h2 id="discount-title">Contas a receber em aberto</h2><p><?= (int)($discountEntries['total'] ?? 0) ?> conta(s)<?= $shortcutContext === null ? ' em todos os condomínios' : '' ?>, por vencimento.</p></div>
        </div>
        <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" role="search" aria-label="Buscar contas" data-erp-loading>
            <div class="erp-field erp-field--compact"><label for="discount-q">Buscar</label><input class="erp-input" type="search" id="discount-q" name="q" value="<?= $this->e($discountQuery) ?>" maxlength="100" placeholder="Descrição ou nº do documento"></div>
            <button type="submit" class="erp-btn"><?= $icon('search', 'erp-icon--sm') ?>Buscar</button>
            <?php if ($discountQuery !== ''): ?><a class="erp-btn erp-btn--ghost" href="<?= $this->e($route) ?>">Limpar</a><?php endif; ?>
        </form>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong><?= $discountQuery !== '' ? 'Nenhuma conta encontrada' : 'Nenhuma conta a receber em aberto' ?></strong><p><?= $discountQuery !== '' ? 'Confira a busca ou limpe o filtro.' : 'As contas das faturas, das cobranças extras e as lançadas à mão aparecem aqui enquanto estiverem em aberto.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="discount-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Vencimento</th><th scope="col">Conta</th><th scope="col">Unidade</th><th scope="col" class="is-num">Valor</th><th scope="col" class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $r): $id = (int)$r['id']; $amount = (int)$r['amount_cents']; $unit = $r['unit_id'] !== null ? ($discountUnits[(int)$r['unit_id']] ?? 'Unidade nº ' . (int)$r['unit_id']) : '—'; $label = $r['description'] . ($unit !== '—' ? ' · ' . $unit : ''); ?><tr data-discount-row="<?= $id ?>">
                    <td class="erp-nowrap"><?= $this->e($date($r['due_on'] ?? null)) ?><?php if (($r['due_on'] ?? '') !== '' && $r['due_on'] < $today): ?><br><span class="erp-badge erp-badge--danger">Vencida</span><?php endif; ?></td>
                    <td><strong><?= $this->e((string)$r['description']) ?></strong><br><span class="erp-muted">Competência <?= $this->e((string)($r['competence'] ?? '')) ?><?= ($r['document_number'] ?? '') !== '' && $r['document_number'] !== null ? ' · ' . $this->e((string)$r['document_number']) : '' ?></span></td>
                    <td><?= $this->e($unit) ?></td>
                    <td class="is-num erp-nowrap" data-discount-amount><?= $this->e($brl($amount)) ?></td>
                    <td class="is-num"><div class="erp-table__actions">
                        <a class="erp-btn erp-btn--sm" href="<?= $this->e(erp_url('/financeiro/contas-a-receber/' . $id)) ?>" aria-label="Abrir a conta <?= $this->e($label) ?>">Abrir</a>
                        <?php if ($shortcutCanManage): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--primary" data-discount-open="<?= $id ?>" data-amount="<?= $amount ?>" data-label="<?= $this->e($label) ?>" aria-label="Dar desconto na conta <?= $this->e($label) ?>">Desconto</button><?php endif; ?>
                    </div></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php if (($discountEntries['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$discountEntries['page'] ?> de <?= (int)$discountEntries['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($discountEntries['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($page((int)$discountEntries['page'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($discountEntries['page'] < $discountEntries['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($page((int)$discountEntries['page'] + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <p class="erp-field__hint erp-discount-rules">Não aceitam desconto: conta de acordo (o valor segue o acordo), conta com boleto ativo (cancele o boleto antes) e conta de competência fechada.</p>
    </section>

    <?php if ($shortcutCanManage): ?>
    <dialog class="erp-modal" id="discount-dialog" aria-labelledby="discount-dialog-title">
        <form data-discount-form data-api="<?= $this->e(url('/api/v1/entries/__ID__/discount')) ?>" novalidate>
            <div class="erp-modal__head"><h2 id="discount-dialog-title">Dar desconto</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p><strong data-discount-label></strong><br><span class="erp-muted">Valor atual: <span data-discount-current></span></span></p>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="discount-amount">Desconto (R$)</label><input class="erp-input" id="discount-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required aria-describedby="discount-amount-hint"><small class="erp-muted" id="discount-amount-hint" data-discount-after>Menor que o valor da conta.</small></div>
                    <div class="erp-field erp-col-12"><label for="discount-reason">Motivo</label><textarea class="erp-textarea" id="discount-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: acordo em assembleia de 10/11"></textarea></div>
                </div>
                <div data-form-error aria-live="assertive"></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary">Dar desconto</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
