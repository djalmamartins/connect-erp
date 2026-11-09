<?php
/**
 * Parcelas de acordo (AP-034, ADR 0039) com dados reais: parcelas dos acordos ativos da página
 * ($agreementParcels, de Panel::agreementData e Panel::flattenParcels), com a situação na data de
 * hoje (paga, vencida ou a vencer). A baixa de cada parcela segue pelos lançamentos ou pelo boleto.
 * @var array $currentPage @var array $agreements @var list<array> $agreementParcels
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$context = \Source\Support\Erp\CondominiumContext::id();
$states = ['open' => ['A vencer', 'info'], 'late' => ['Vencida', 'danger'], 'paid' => ['Paga', 'success']];
$rows = $agreementParcels ?? [];
$sum = static fn (string $state): int => array_sum(array_map(static fn (array $p): int => $p['state'] === $state ? (int)$p['amount_cents'] : 0, $rows));
$count = static fn (string $state): int => count(array_filter($rows, static fn (array $p): bool => $p['state'] === $state));

ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e(erp_url($catalog['pages']['AP-033']['route'] ?? '/cobranca/acordos')) ?>"><?= $icon('file', 'erp-icon--sm') ?>Acordos</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita nos lançamentos ou pelo boleto.',
    'pageActions' => $actions,
]);
?>
<section class="erp-grid erp-grid--3" aria-label="Resumo das parcelas">
    <?php foreach ([
        ['label' => 'Vencidas', 'value' => $brl($sum('late')), 'meta' => $count('late') . ' parcela(s)', 'icon' => 'alert', 'tone' => $count('late') > 0 ? 'danger' : '', 'trend' => null],
        ['label' => 'A vencer', 'value' => $brl($sum('open')), 'meta' => $count('open') . ' parcela(s)', 'icon' => 'calendar', 'tone' => 'info', 'trend' => null],
        ['label' => 'Pagas', 'value' => $brl($sum('paid')), 'meta' => $count('paid') . ' parcela(s)', 'icon' => 'check', 'tone' => '', 'trend' => null],
    ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
</section>

<section class="erp-card" aria-labelledby="par-list">
    <div class="erp-card__head"><div><h2 id="par-list">Parcelas</h2><p><?= count($rows) ?> parcela(s) de <?= count($agreements['data'] ?? []) ?> acordo(s) ativo(s)<?= ($agreements['pages'] ?? 0) > 1 ? ' nesta página' : '' ?><?= $context === null ? ', em todos os condomínios do seu escopo' : '' ?></p></div></div>
    <?php if ($rows === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('calendar') ?></span><strong>Nenhuma parcela</strong><p>Não há acordos ativos.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="par-list" tabindex="0"><table class="erp-table">
            <thead><tr><th>Acordo</th><th>Parcela</th><th>Unidade</th><th>Vencimento</th><th class="is-num">Valor</th><th>Situação</th></tr></thead>
            <tbody><?php foreach ($rows as $parcel): [$label, $tone] = $states[$parcel['state']] ?? ['—', 'plain']; ?>
                <tr>
                    <td>Nº <?= (int)$parcel['agreement_id'] ?></td>
                    <td><?= (int)$parcel['number'] ?>/<?= (int)$parcel['installments'] ?><small class="erp-muted">lançamento nº <?= (int)$parcel['entry_id'] ?></small></td>
                    <td><?= $this->e((string)$parcel['unit_label']) ?><?php if ($context === null): ?><small class="erp-muted"><?= $this->e((string)$parcel['condominium_name']) ?></small><?php endif; ?></td>
                    <td><?= $this->e($date((string)$parcel['due_on'])) ?><?php if ($parcel['state'] === 'paid'): ?><small class="erp-muted">paga <?= $this->e($date($parcel['paid_on'])) ?></small><?php endif; ?></td>
                    <td class="is-num"><?= $brl((int)$parcel['amount_cents']) ?></td>
                    <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if (($agreements['pages'] ?? 0) > 1): ?>
            <nav class="erp-pagination" aria-label="Paginação">
                <span>Acordos: página <?= (int)$agreements['page'] ?> de <?= (int)$agreements['pages'] ?></span>
                <div class="erp-pagination__pages">
                    <?php if ($agreements['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($agreements['page'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
                    <?php if ($agreements['page'] < $agreements['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($agreements['page'] + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
