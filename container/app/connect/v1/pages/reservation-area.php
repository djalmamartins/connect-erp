<?php
/**
 * Calendário de uma área comum (ADR 0302, 14/11): mês com dias livres, parte do dia, ocupados e fora do prazo; ao
 * escolher o dia, os horários já tomados (sem dizer de quem), o pedido e a lista de espera.
 *
 * @var array $area @var string $month @var array<string,string> $calendar ResidentPortal::calendar() @var ?string $day @var list<array> $taken @var string $today
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$first = new DateTimeImmutable($month . '-01');
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$base = app_url('/reservas/area/' . $area['id']);
$canPrev = $prev >= substr($today, 0, 7);
$pickable = ['free', 'partial'];
$state = $day !== null ? $calendar[$day] : null;
$labels = ['free' => 'Livre', 'partial' => 'Parte do dia', 'busy' => 'Ocupado', 'out' => 'Fora do prazo', 'blocked' => 'Reservas suspensas', 'past' => 'Já passou'];
?>
<header class="app-page-head">
    <a class="app-back" href="<?= app_url('/reservas') ?>"><svg aria-hidden="true"><use href="#i-chevron"></use></svg>Reservas</a>
    <h1><?= $this->e($area['name']) ?></h1>
</header>

<div class="app-card app-item app-area-info">
    <span class="app-item__icon" aria-hidden="true"><svg><use href="#i-calendar"></use></svg></span>
    <span class="app-item__text">
        <strong><?= $area['fee_cents'] ? F::money($area['fee_cents']) . ' · vem no boleto' : 'Gratuito' ?></strong>
        <small><?= $this->e(implode(' · ', array_filter([
            $area['capacity'] ? 'Até ' . (int)$area['capacity'] . ' pessoas' : '',
            $area['opening_time'] ? $area['opening_time'] . ' às ' . $area['closing_time'] : '',
            $area['booking_mode'] === 'blocked' ? 'reservas suspensas' : 'precisa de aprovação do síndico',
        ]))) ?></small>
    </span>
</div>

<section class="app-card app-calendar" aria-label="Calendário">
    <div class="app-calendar__head">
        <?php if ($canPrev): ?><a href="<?= $base ?>?mes=<?= $prev ?>" aria-label="Mês anterior" class="app-calendar__nav app-calendar__nav--prev"><svg aria-hidden="true"><use href="#i-chevron"></use></svg></a><?php else: ?><span class="app-calendar__nav is-off" aria-hidden="true"><svg><use href="#i-chevron"></use></svg></span><?php endif; ?>
        <strong><?= $months[(int)$first->format('n') - 1] ?> de <?= $first->format('Y') ?></strong>
        <a href="<?= $base ?>?mes=<?= $next ?>" aria-label="Próximo mês" class="app-calendar__nav"><svg aria-hidden="true"><use href="#i-chevron"></use></svg></a>
    </div>
    <div class="app-calendar__grid" role="grid">
        <?php foreach (['D', 'S', 'T', 'Q', 'Q', 'S', 'S'] as $w): ?><span class="app-calendar__dow" aria-hidden="true"><?= $w ?></span><?php endforeach; ?>
        <?php for ($i = 0, $pad = (int)$first->format('w'); $i < $pad; $i++): ?><span></span><?php endfor; ?>
        <?php foreach ($calendar as $date => $s): $n = (int)substr($date, 8, 2); $picked = $date === $day; ?>
            <?php if (in_array($s, $pickable, true) || $s === 'busy'): ?>
                <a class="app-day app-day--<?= $s ?><?= $picked ? ' is-picked' : '' ?>" href="<?= $base ?>?dia=<?= $date ?>#dia" aria-label="<?= F::date($date) ?>: <?= $labels[$s] ?>"<?= $picked ? ' aria-current="date"' : '' ?>><?= $n ?></a>
            <?php else: ?>
                <span class="app-day app-day--<?= $s ?>" title="<?= $labels[$s] ?>"><?= $n ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <p class="app-legend"><span class="app-legend__ring"></span>Livre <span class="app-legend__ring app-legend__ring--warning"></span>Parte do dia <span class="app-legend__ring app-legend__ring--danger"></span>Ocupado <span class="app-legend__dot app-legend__dot--success"></span>Escolhido</p>
    <?php if ($day === null): ?><p class="app-hint">Toque num dia para ver os horários e pedir a reserva.</p><?php endif; ?>
</section>

<?php if ($day !== null): ?>
<section class="app-card app-form-card app-day-panel" id="dia">
    <h2><?= F::date($day) ?></h2>
    <?php if ($taken !== []): ?>
        <p class="app-day-panel__taken"><strong>Já reservado</strong>
            <?php foreach ($taken as $t): ?><span><?= $t['starts_time'] ? $this->e($t['starts_time'] . ' às ' . $t['ends_time']) : 'Dia todo' ?></span><?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php if (in_array($state, $pickable, true)): ?>
        <form method="post" action="<?= app_url('/reservas') ?>" class="app-form">
            <?= csrf_input() ?>
            <input type="hidden" name="common_area_id" value="<?= (int)$area['id'] ?>">
            <input type="hidden" name="reserved_on" value="<?= $day ?>">
            <input type="hidden" name="from_calendar" value="1">
            <div class="app-field-row">
                <label class="app-field">Das <input type="time" name="starts_time" value="<?= $this->e($area['opening_time'] ?? '') ?>"></label>
                <label class="app-field">Até <input type="time" name="ends_time" value="<?= $this->e($area['closing_time'] ?? '') ?>"></label>
            </div>
            <label class="app-field">Observação (opcional) <textarea name="notes" rows="2" maxlength="1000"></textarea></label>
            <button class="app-button" type="submit">Reservar</button>
        </form>
    <?php endif; ?>
    <form method="post" action="<?= app_url('/reservas/lista-de-espera') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="common_area_id" value="<?= (int)$area['id'] ?>">
        <input type="hidden" name="desired_on" value="<?= $day ?>">
        <input type="hidden" name="from_calendar" value="1">
        <button class="app-button app-button--ghost app-button--block" type="submit">Entrar na lista de espera</button>
    </form>
    <?php if ($area['rules'] !== ''): ?><p class="app-hint"><?= nl2br($this->e($area['rules'])) ?></p><?php endif; ?>
</section>
<?php elseif ($area['rules'] !== ''): ?>
<section class="app-section"><h2>Regras</h2><div class="app-card"><p class="app-notice__body"><?= nl2br($this->e($area['rules'])) ?></p></div></section>
<?php endif; ?>
