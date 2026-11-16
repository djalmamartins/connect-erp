<?php
/**
 * Reservas no padrão do app de celular (ADR 0302, 14/11): abas Nova reserva / Minhas reservas, filtro por taxa e um
 * cartão por espaço que leva ao calendário do mês.
 *
 * @var list<array> $areas @var array{reservations:list<array>, waitlist:list<array>} $mine @var string $tab @var string $filter
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$today = date('Y-m-d');
$shown = array_values(array_filter($areas, static fn (array $a): bool => match ($filter) {
    'gratuitos' => !$a['fee_cents'],
    'taxa' => (bool)$a['fee_cents'],
    default => true,
}));
$upcoming = array_values(array_filter($mine['reservations'], static fn (array $r): bool => $r['reserved_on'] >= $today && $r['status'] !== 'inactive'));
$url = static fn (array $q): string => app_url('/reservas') . '?' . http_build_query($q);
$dot = ['confirmed' => 'success', 'active' => 'warning', 'inactive' => 'danger'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Reservas</h1><p>Salão, churrasqueira, quadra e outras áreas do condomínio.</p></header>

<nav class="app-segment" aria-label="Reservas">
    <a href="<?= $url(['aba' => 'nova']) ?>"<?= $tab === 'nova' ? ' aria-current="page"' : '' ?>>Nova reserva</a>
    <a href="<?= $url(['aba' => 'minhas']) ?>"<?= $tab === 'minhas' ? ' aria-current="page"' : '' ?>>Minhas reservas<?= $upcoming !== [] ? ' (' . count($upcoming) . ')' : '' ?></a>
</nav>

<?php if ($tab === 'nova'): ?>
    <div class="app-chips" role="group" aria-label="Filtrar espaços">
        <?php foreach (['todos' => 'Todos', 'gratuitos' => 'Gratuitos', 'taxa' => 'Com taxa'] as $key => $label): ?>
            <a class="app-chip<?= $filter === $key ? ' is-active' : '' ?>" href="<?= $url(['aba' => 'nova', 'filtro' => $key]) ?>"<?= $filter === $key ? ' aria-current="true"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($shown === []): ?>
        <?php $this->insert('partials/empty', ['icon' => 'calendar', 'text' => $areas === [] ? 'O condomínio ainda não cadastrou áreas para reserva.' : 'Nenhum espaço neste filtro.']) ?>
    <?php endif; ?>
    <ul class="app-items">
    <?php foreach ($shown as $a):
        $lines = [$a['fee_cents'] ? F::money($a['fee_cents']) . ' · vem no boleto' : 'Gratuito'];
        $details = array_filter([$a['capacity'] ? 'Até ' . (int)$a['capacity'] . ' pessoas' : '', $a['opening_time'] ? $a['opening_time'] . ' às ' . $a['closing_time'] : '']);
        if ($details !== []) { $lines[] = implode(' · ', $details); }
        $lines[] = $a['booking_mode'] === 'blocked' ? 'Reservas suspensas' : 'Com aprovação do síndico';
        $this->insert('partials/item', ['icon' => 'calendar', 'tone' => $a['booking_mode'] === 'blocked' ? 'neutral' : 'brand', 'title' => $a['name'], 'lines' => $lines,
            'href' => app_url('/reservas/area/' . $a['id']), 'badge' => $a['booking_mode'] === 'blocked' ? ['Bloqueada', 'neutral'] : null]);
    endforeach; ?>
    </ul>
<?php else: ?>
    <p class="app-legend"><span class="app-legend__dot app-legend__dot--success"></span>Confirmada <span class="app-legend__dot app-legend__dot--warning"></span>Aguardando aprovação <span class="app-legend__dot app-legend__dot--danger"></span>Cancelada</p>
    <?php if ($mine['reservations'] === []): ?>
        <?php $this->insert('partials/empty', ['icon' => 'calendar', 'text' => 'Nenhuma reserva recente.', 'action' => ['Reservar um espaço', $url(['aba' => 'nova'])]]) ?>
    <?php endif; ?>
    <ul class="app-items">
    <?php foreach ($mine['reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']);
        $cancel = $r['status'] !== 'inactive' && $r['reserved_on'] >= $today ? '<form method="post" action="' . app_url('/reservas/' . $r['id'] . '/cancelar') . '" data-app-confirm="Cancelar esta reserva? O horário fica livre para outra unidade." data-confirm-undo="nao" data-confirm-undo-text="Para usar o espaço, faça uma reserva nova (se o horário ainda estiver livre)." data-confirm-action="Cancelar reserva">' . csrf_input() . '<button class="app-link-button" type="submit">Cancelar</button></form>' : null;
        $this->insert('partials/item', ['date' => $r['reserved_on'], 'title' => $r['area'],
            'lines' => [($r['starts_time'] ? $r['starts_time'] . ' às ' . $r['ends_time'] : 'Dia todo') . ($r['fee_cents'] ? ' · taxa ' . F::money($r['fee_cents']) . ' no boleto' : '')],
            'badge' => [$label, $dot[$r['status']] ?? $tone], 'actions' => $cancel]);
    endforeach; ?>
    </ul>
    <?php if ($mine['waitlist'] !== []): ?>
        <section class="app-section">
            <h2>Lista de espera</h2>
            <ul class="app-items">
            <?php foreach ($mine['waitlist'] as $w):
                $this->insert('partials/item', ['icon' => 'calendar', 'tone' => 'info', 'title' => $w['area'], 'lines' => ['Data desejada ' . F::date($w['desired_on'])], 'badge' => ['Aguardando', 'info']]);
            endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endif; ?>
