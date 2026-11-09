<?php
/**
 * Retornos com divergência (AP-038, ADR 0035 e 0036): fila de revisão das ocorrências do retorno
 * que não deram baixa automática ($bankDivergences, de Panel::bankingData, no contexto da barra
 * superior). "Marcar como revisada" grava uma nota pela API POST /api/v1/bank-events/{id}/resolve
 * (erp.js, data-erp-bank-divergences); a correção em si é feita à mão no lançamento ou no boleto.
 * @var array $currentPage @var list<array> $bankDivergences @var bool $canManageCharges @var list<array> $condoOptions
 */
$this->layout('default');
$brl = static fn (?int $cents): string => $cents === null ? '—' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$kinds = ['paid' => 'Pagamento', 'registered' => 'Registro', 'rejected' => 'Recusa', 'cancelled' => 'Baixa pelo banco'];
$context = \Source\Support\Erp\CondominiumContext::id();
$condoNames = [];
foreach ($condoOptions ?? [] as $condo) {
    $condoNames[(int)$condo['id']] = (string)$condo['name'];
}
$rows = $bankDivergences ?? [];
$canManageCharges ??= false;

ob_start(); ?>
    <a class="erp-btn<?= $canManageCharges ? ' erp-btn--primary' : '' ?>" href="<?= $this->e(erp_url($catalog['pages']['AP-036']['route'] ?? '/cobranca/arquivo-de-retorno')) ?>"><?= $icon('upload', 'erp-icon--sm') ?>Importar retorno</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Ocorrências do arquivo de retorno que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou nosso número desconhecido. Corrija à mão e marque como revisada.',
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-bank-divergences data-api="<?= $this->e(url('/api/v1/bank-events')) ?>">
    <?= csrf_input() ?>
    <section class="erp-card" aria-labelledby="div-list">
        <div class="erp-card__head"><div><h2 id="div-list">Divergências a revisar</h2><p><?= count($rows) ?> ocorrência(s)<?= $context === null ? ' em todos os condomínios do seu escopo' : '' ?><?= count($rows) >= 200 ? ' (mostrando as 200 mais recentes)' : '' ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma divergência pendente</strong><p>Todas as ocorrências dos retornos importados deram baixa ou já foram revisadas.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="div-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Data</th><?php if ($context === null): ?><th>Condomínio</th><?php endif; ?><th>Nosso número</th><th>Ocorrência</th><th class="is-num">Valor</th><th>Motivo</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $event): ?>
                    <tr>
                        <td><?= $this->e($date($event['occurred_on'] ?? null)) ?></td>
                        <?php if ($context === null): ?><td><?= $this->e($condoNames[(int)$event['condominium_id']] ?? ('Condomínio nº ' . (int)$event['condominium_id'])) ?></td><?php endif; ?>
                        <td><strong><?= $this->e((string)$event['our_number']) ?></strong></td>
                        <td><?= $this->e($kinds[$event['kind']] ?? (string)$event['kind']) ?></td>
                        <td class="is-num"><?= $brl($event['amount_cents']) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $event['outcome'] === 'unknown_charge' ? 'danger' : 'warning' ?>"><?= $this->e((string)$event['outcome_label']) ?></span><?php if (!empty($event['note'])): ?><small class="erp-muted"><?= $this->e((string)$event['note']) ?></small><?php endif; ?></td>
                        <td class="is-num erp-invoice-actions"><?php if ($canManageCharges): ?><button type="button" class="erp-btn erp-btn--sm" data-divergence-resolve="<?= (int)$event['id'] ?>" data-label="<?= $this->e($event['our_number'] . ' · ' . ($kinds[$event['kind']] ?? $event['kind'])) ?>">Marcar como revisada</button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($canManageCharges): ?>
    <dialog class="erp-modal" id="divergence-resolve" aria-labelledby="divergence-resolve-title">
        <form data-divergence-resolve-form>
            <div class="erp-modal__head"><h2 id="divergence-resolve-title">Marcar como revisada</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p data-divergence-label></p>
                <p class="erp-muted">Marcar só registra a revisão. Corrija antes o lançamento ou o boleto, se for preciso.</p>
                <div class="erp-field"><label for="div-note">O que foi feito</label><textarea class="erp-textarea" id="div-note" name="note" minlength="5" maxlength="255" required placeholder="Ex.: diferença devolvida ao condômino; baixa manual no lançamento"></textarea></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Marcar como revisada</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
