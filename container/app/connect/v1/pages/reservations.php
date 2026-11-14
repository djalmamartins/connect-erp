<?php
/** Reservas de áreas comuns e lista de espera (ADR 0302). @var list<array> $areas @var array{reservations:list<array>, waitlist:list<array>} $mine */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$today = date('Y-m-d');
$bookable = array_values(array_filter($areas, static fn (array $a): bool => in_array($a['booking_mode'], ['approval', 'free'], true)));
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Reservas</h1><p>Salão, churrasqueira, quadra e outras áreas do condomínio.</p></header>

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
    <?php if ($mine['reservations'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'calendar', 'text' => 'Nenhuma reserva recente.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($mine['reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']);
        $cancel = $r['status'] !== 'inactive' && $r['reserved_on'] >= $today ? '<form method="post" action="' . app_url('/reservas/' . $r['id'] . '/cancelar') . '" data-app-confirm="Cancelar esta reserva?">' . csrf_input() . '<button class="app-link-button" type="submit">Cancelar</button></form>' : null;
        $this->insert('partials/item', ['date' => $r['reserved_on'], 'title' => $r['area'],
            'lines' => [($r['starts_time'] ? $r['starts_time'] . ' às ' . $r['ends_time'] : 'Dia todo') . ($r['fee_cents'] ? ' · taxa ' . F::money($r['fee_cents']) : '')],
            'badge' => [$label, $tone], 'actions' => $cancel]);
    endforeach; ?>
    </ul>
</section>

<?php if ($bookable !== []): ?>
<section class="app-section">
    <h2>Lista de espera</h2>
    <?php if ($mine['waitlist'] !== []): ?><ul class="app-items">
    <?php foreach ($mine['waitlist'] as $w):
        $this->insert('partials/item', ['icon' => 'calendar', 'tone' => 'info', 'title' => $w['area'], 'lines' => ['Data desejada ' . F::date($w['desired_on'])], 'badge' => ['Aguardando', 'info']]);
    endforeach; ?>
    </ul><?php endif; ?>
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
    <?php if ($areas === []): ?><?php $this->insert('partials/empty', ['icon' => 'calendar', 'text' => 'O condomínio ainda não cadastrou áreas para reserva.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($areas as $a):
        $facts = [$a['booking_mode'] === 'blocked' ? 'Reservas suspensas no momento' : 'Reserva com aprovação do síndico'];
        if ($a['opening_time']) { $facts[] = 'Funciona das ' . $a['opening_time'] . ' às ' . $a['closing_time']; }
        if ($a['min_advance_days']) { $facts[] = 'Antecedência mínima de ' . (int)$a['min_advance_days'] . ' dia(s)'; }
        if ($a['max_advance_days']) { $facts[] = 'Até ' . (int)$a['max_advance_days'] . ' dia(s) antes'; }
        if ($a['capacity']) { $facts[] = 'Capacidade: ' . (int)$a['capacity'] . ' pessoas'; }
        if ($a['fee_cents']) { $facts[] = 'Taxa: ' . F::money($a['fee_cents']); }
        $body = '<ul class="app-facts">' . implode('', array_map(fn (string $f): string => '<li>' . $this->e($f) . '</li>', $facts)) . '</ul>'
            . ($a['rules'] !== '' ? '<p class="app-notice__body">' . nl2br($this->e($a['rules'])) . '</p>' : '');
        $this->insert('partials/item', ['icon' => 'calendar', 'tone' => $a['booking_mode'] === 'blocked' ? 'neutral' : 'brand', 'title' => $a['name'],
            'lines' => [$facts[0]], 'badge' => $a['booking_mode'] === 'blocked' ? ['Bloqueada', 'neutral'] : ($a['fee_cents'] ? [F::money($a['fee_cents']), 'neutral'] : null), 'body' => $body]);
    endforeach; ?>
    </ul>
</section>
