<?php
/** Reservas de áreas comuns e lista de espera (ADR 0302). @var list<array> $areas @var array{reservations:list<array>, waitlist:list<array>} $mine */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$today = date('Y-m-d');
$bookable = array_values(array_filter($areas, static fn (array $a): bool => in_array($a['booking_mode'], ['approval', 'free'], true)));
?>
<header class="app-page-head"><h1>Reservas</h1><p>Salão, churrasqueira, quadra e outras áreas do condomínio.</p></header>

<?php if ($bookable !== []): ?>
<details class="app-card app-form-card" open>
    <summary><h2>Pedir reserva</h2></summary>
    <form method="post" action="<?= app_url('/reservas') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-field">Área
            <select name="common_area_id" required>
                <?php foreach ($bookable as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['name']) ?><?= $a['fee_cents'] ? ' · taxa ' . F::money($a['fee_cents']) : '' ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="app-field">Data <input type="date" name="reserved_on" min="<?= $today ?>" required></label>
        <div class="app-field-row">
            <label class="app-field">Das <input type="time" name="starts_time"></label>
            <label class="app-field">Até <input type="time" name="ends_time"></label>
        </div>
        <label class="app-field">Observação <textarea name="notes" rows="2" maxlength="1000"></textarea></label>
        <button class="app-button" type="submit">Pedir reserva</button>
        <p class="app-hint">Áreas com aprovação ficam aguardando a administração. Se a data estiver ocupada, entre na lista de espera abaixo.</p>
    </form>
</details>
<?php endif; ?>

<section class="app-section">
    <h2>Minhas reservas</h2>
    <?php if ($mine['reservations'] === []): ?><p class="app-muted">Nenhuma reserva recente.</p><?php endif; ?>
    <ul class="app-list">
    <?php foreach ($mine['reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']); ?>
        <li class="app-card app-row">
            <span><strong><?= $this->e($r['area']) ?></strong><small><?= F::date($r['reserved_on']) ?><?= $r['starts_time'] ? ' · ' . $r['starts_time'] . ' às ' . $r['ends_time'] : '' ?><?= $r['fee_cents'] ? ' · taxa ' . F::money($r['fee_cents']) : '' ?></small></span>
            <span class="app-row__end">
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
                <?php if ($r['status'] !== 'inactive' && $r['reserved_on'] >= $today): ?>
                    <form method="post" action="<?= app_url('/reservas/' . $r['id'] . '/cancelar') ?>" data-app-confirm="Cancelar esta reserva?">
                        <?= csrf_input() ?><button class="app-link-button" type="submit">Cancelar</button>
                    </form>
                <?php endif; ?>
            </span>
        </li>
    <?php endforeach; ?>
    </ul>
</section>

<?php if ($bookable !== []): ?>
<section class="app-section">
    <h2>Lista de espera</h2>
    <?php foreach ($mine['waitlist'] as $w): ?>
        <div class="app-card app-row"><span><strong><?= $this->e($w['area']) ?></strong><small>Data desejada <?= F::date($w['desired_on']) ?></small></span><span class="app-badge app-badge--info">Aguardando</span></div>
    <?php endforeach; ?>
    <details class="app-card app-form-card">
        <summary>Entrar na lista de espera</summary>
        <form method="post" action="<?= app_url('/reservas/lista-de-espera') ?>" class="app-form">
            <?= csrf_input() ?>
            <label class="app-field">Área
                <select name="common_area_id" required><?php foreach ($bookable as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="app-field">Data desejada <input type="date" name="desired_on" min="<?= $today ?>" required></label>
            <button class="app-button app-button--ghost" type="submit">Entrar na lista</button>
        </form>
    </details>
</section>
<?php endif; ?>

<section class="app-section">
    <h2>Áreas e regras</h2>
    <?php if ($areas === []): ?><p class="app-muted">O condomínio ainda não cadastrou áreas para reserva.</p><?php endif; ?>
    <?php foreach ($areas as $a): ?>
        <details class="app-card app-area">
            <summary><strong><?= $this->e($a['name']) ?></strong><?php if ($a['booking_mode'] === 'blocked'): ?><span class="app-badge app-badge--neutral">Bloqueada</span><?php endif; ?></summary>
            <ul class="app-facts">
                <li><?= $a['booking_mode'] === 'free' ? 'Reserva confirmada na hora' : ($a['booking_mode'] === 'approval' ? 'Reserva com aprovação da administração' : 'Reservas suspensas no momento') ?></li>
                <?php if ($a['opening_time']): ?><li>Funciona das <?= $a['opening_time'] ?> às <?= $a['closing_time'] ?></li><?php endif; ?>
                <?php if ($a['min_advance_days']): ?><li>Antecedência mínima de <?= (int)$a['min_advance_days'] ?> dia(s)</li><?php endif; ?>
                <?php if ($a['max_advance_days']): ?><li>Até <?= (int)$a['max_advance_days'] ?> dia(s) antes</li><?php endif; ?>
                <?php if ($a['capacity']): ?><li>Capacidade: <?= (int)$a['capacity'] ?> pessoas</li><?php endif; ?>
                <?php if ($a['fee_cents']): ?><li>Taxa: <?= F::money($a['fee_cents']) ?></li><?php endif; ?>
            </ul>
            <?php if ($a['rules'] !== ''): ?><p class="app-notice__body"><?= nl2br($this->e($a['rules'])) ?></p><?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>
