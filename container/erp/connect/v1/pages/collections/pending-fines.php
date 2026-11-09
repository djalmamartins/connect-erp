<?php
/**
 * Multas pendentes (AP-116; ADR 0080 e 0076) com dados reais: as multas avulsas ativas ainda não
 * pagas (?pagas=1 traz também as pagas), com a situação e a origem (ocorrência ou reserva). Lançar e
 * cancelar a multa ficam na AP-027.
 * @var array $currentPage @var ?int $collContext @var bool $finesPaid @var array $fines
 */
use Source\Controllers\Erp\Connect\CollectionScreens;

$this->layout('default');
$route = erp_url($currentPage['route']);
$fineRoute = $catalog['pages']['AP-027']['route'];
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$rows = $fines['data'];
$totals = $fines['totals'];

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Multas avulsas que a unidade ainda não pagou, com o vencimento e o que as motivou. A multa entra na cobrança da unidade como qualquer conta a receber.',
    'pageActions' => '<a class="erp-btn erp-btn--primary" href="' . $this->e(erp_url($fineRoute)) . '">' . $icon('plus', 'erp-icon--sm') . 'Lançar multa</a>',
]);
?>
<div class="erp-stack">
    <section class="erp-card" aria-labelledby="fines-title">
        <div class="erp-card__head"><div><h2 id="fines-title"><?= $finesPaid ? 'Multas' : 'Multas não pagas' ?></h2><p><?= (int)$totals['fines'] ?> multa(s)<?= $collContext === null ? ' em todos os condomínios' : '' ?> · em aberto <?= $this->e($brl((int)$totals['open_cents'])) ?> · <?= (int)$totals['overdue'] ?> vencida(s).</p></div>
            <nav class="erp-comm-filter" aria-label="Filtrar multas">
                <a class="erp-btn erp-btn--sm<?= $finesPaid ? '' : ' erp-btn--primary' ?>" href="<?= $this->e($route) ?>"<?= $finesPaid ? '' : ' aria-current="page"' ?>>Não pagas</a>
                <a class="erp-btn erp-btn--sm<?= $finesPaid ? ' erp-btn--primary' : '' ?>" href="<?= $this->e($route . '?pagas=1') ?>"<?= $finesPaid ? ' aria-current="page"' : '' ?>>Com as pagas</a>
            </nav>
        </div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma multa pendente</strong><p>As multas avulsas lançadas e ainda não pagas aparecem aqui.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="fines-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Unidade</th><th scope="col">Multa</th><th scope="col">Origem</th><th scope="col">Vencimento</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th></tr></thead>
                <tbody><?php foreach ($rows as $f): [$label, $tone] = CollectionScreens::fineBadge($f['state']); ?><tr>
                    <td><?= $this->e($f['unit_label']) ?><?= $collContext === null ? '<br><span class="erp-muted">' . $this->e($f['condominium_name']) . '</span>' : '' ?></td>
                    <td><a href="<?= $this->e(erp_url($fineRoute . '?cobranca=' . (int)$f['id'])) ?>"><?= $this->e($f['description']) ?></a><?php if ($f['reason']): ?><br><span class="erp-muted"><?= $this->e((string)$f['reason']) ?></span><?php endif; ?></td>
                    <td><?php if ($f['occurrence_id'] !== null): ?><a href="<?= $this->e(erp_url('/operacional/ocorrencias/' . (int)$f['occurrence_id'])) ?>">Ocorrência nº <?= (int)$f['occurrence_id'] ?></a><?php elseif ($f['reservation_id'] !== null): ?><a href="<?= $this->e(erp_url('/operacional/reservas-areas-comuns/' . (int)$f['reservation_id'])) ?>">Reserva nº <?= (int)$f['reservation_id'] ?></a><?php else: ?><span class="erp-muted">Não registrada</span><?php endif; ?></td>
                    <td class="erp-nowrap"><?= $this->e($date($f['due_on'])) ?><?= $f['paid_on'] ? '<br><span class="erp-muted">paga ' . $this->e($date($f['paid_on'])) . '</span>' : '' ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e($brl((int)$f['amount_cents'])) ?></td>
                    <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
