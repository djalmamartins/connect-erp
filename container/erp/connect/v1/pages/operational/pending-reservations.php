<?php
/**
 * Agendamentos Pendentes (AP-114, ADR 0051 e 0070) com dados reais: as reservas de áreas comuns
 * (AP-113) aguardando aprovação no condomínio da barra superior ($pendingReservations de
 * Panel::pendingReservationData). Confirmar e recusar são POST com CSRF em
 * /operacional/agendamentos-pendentes/{id}/confirmar|recusar (PendingReservations).
 * @var array $currentPage @var array $pendingReservations @var array<int,string> $pendingAreas
 * @var array<int,string> $pendingUnits @var bool $canDecideReservations
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$reservationsRoute = erp_url('/operacional/reservas-areas-comuns');
$rows = $pendingReservations['data'] ?? [];
$context = \Source\Support\Erp\CondominiumContext::id();
$money = static fn (?int $cents): string => $cents === null ? '—' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$day = static fn (?string $date): string => $date ? date('d/m/Y', (int)strtotime($date)) : '—';
$weekday = static function (?string $date): string {
    $names = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    return $date ? $names[(int)date('w', (int)strtotime($date))] : '';
};
$hours = static fn (array $r): string => ($r['starts_time'] ?? null) !== null
    ? $r['starts_time'] . (($r['ends_time'] ?? null) !== null ? ' às ' . $r['ends_time'] : '')
    : 'Dia inteiro';

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Reservas de áreas comuns que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva.',
    'pageActions' => '<a class="erp-btn" href="' . $this->e($reservationsRoute) . '">' . $icon('calendar', 'erp-icon--sm') . 'Todas as reservas</a>',
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>

<section class="erp-card" aria-labelledby="pending-title" data-erp-pending-reservations>
    <div class="erp-card__head">
        <div><h2 id="pending-title">Aguardando aprovação</h2><p><?= (int)($pendingReservations['total'] ?? 0) ?> pedido(s)<?= $context === null ? ' em todos os condomínios' : '' ?>.</p></div>
    </div>
    <?php if ($rows === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma reserva pendente</strong><p>As reservas das áreas que exigem aprovação aparecem aqui. As de reserva livre já entram confirmadas.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="pending-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Data</th><th scope="col">Área comum</th><th scope="col">Unidade e morador</th><th scope="col" class="is-num">Taxa</th><th scope="col" class="is-num">Ações</th></tr></thead>
            <tbody><?php foreach ($rows as $r): $id = (int)$r['id']; $label = ($pendingAreas[(int)$r['common_area_id']] ?? 'Área nº ' . (int)$r['common_area_id']) . ' em ' . $day($r['reserved_on']); ?><tr>
                <td class="erp-nowrap"><strong><?= $this->e($day($r['reserved_on'])) ?></strong><br><span class="erp-muted"><?= $this->e($weekday($r['reserved_on'])) ?> · <?= $this->e($hours($r)) ?></span></td>
                <td><?= $this->e($pendingAreas[(int)$r['common_area_id']] ?? 'Área nº ' . (int)$r['common_area_id']) ?><?php if ($context === null): ?><br><span class="erp-muted"><?= $this->e((string)($r['condominium_name'] ?? '')) ?></span><?php endif; ?></td>
                <td><?= $this->e($pendingUnits[(int)$r['unit_id']] ?? 'Unidade nº ' . (int)$r['unit_id']) ?><br><span class="erp-muted"><?= $this->e((string)$r['resident_name']) ?></span></td>
                <td class="is-num erp-nowrap"><?= $this->e($money($r['fee_cents'] ?? null)) ?></td>
                <td class="is-num"><div class="erp-table__actions">
                    <a class="erp-btn erp-btn--sm" href="<?= $this->e($reservationsRoute . '/' . $id) ?>" aria-label="Abrir a reserva de <?= $this->e($label) ?>">Abrir</a>
                    <?php if ($canDecideReservations): ?>
                        <form method="post" action="<?= $this->e($route . '/' . $id . '/confirmar') ?>" data-erp-loading><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--sm erp-btn--primary" aria-label="Confirmar a reserva de <?= $this->e($label) ?>"><?= $icon('check', 'erp-icon--sm') ?>Confirmar</button></form>
                        <form method="post" action="<?= $this->e($route . '/' . $id . '/recusar') ?>" data-erp-confirm="Recusar a reserva de <?= $this->e($label) ?>? O horário fica livre para outra reserva."><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--sm" aria-label="Recusar a reserva de <?= $this->e($label) ?>">Recusar</button></form>
                    <?php endif; ?>
                </div></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php if (($pendingReservations['pages'] ?? 0) > 1): ?>
            <nav class="erp-pagination" aria-label="Paginação">
                <span>Página <?= (int)$pendingReservations['page'] ?> de <?= (int)$pendingReservations['pages'] ?></span>
                <div class="erp-pagination__pages">
                    <?php if ($pendingReservations['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($pendingReservations['page'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
                    <?php if ($pendingReservations['page'] < $pendingReservations['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($pendingReservations['page'] + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
