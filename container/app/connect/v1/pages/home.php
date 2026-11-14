<?php
/** Início do app do morador (ADR 0302), no visual do Realizze PF. @var array $summary ResidentPortal::summary() @var array $link @var list<array> $menu */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$features = $summary['features'];
$first = explode(' ', trim((string)$link['name']))[0] ?? '';
?>
<header class="app-hello">
    <span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span>
    <h1>Olá, <?= $this->e($first) ?></h1>
    <p><?= $this->e($link['relationship_label']) ?> · <?= $this->e($link['unit_label']) ?></p>
</header>

<?php if ($features === []): ?>
    <div class="app-card app-empty">
        <h2>O aplicativo ainda está sendo preparado</h2>
        <p>O seu condomínio ainda não liberou as funções do aplicativo. Assim que a administração ligar, elas aparecem aqui.</p>
    </div>
<?php endif; ?>

<div class="app-grid">
<?php if (isset($summary['charges'])): $c = $summary['charges']; ?>
    <a class="app-card app-stat app-hero" href="<?= app_url('/boletos') ?>">
        <span class="app-stat__label">Boletos em aberto</span>
        <strong class="app-stat__value"><?= F::money($c['open_cents']) ?></strong>
        <span class="app-stat__hint"><?php if ($c['open_count'] === 0): ?>Nada em aberto. Obrigado!<?php elseif ($c['next_due_on']): ?>Próximo vencimento <?= F::date($c['next_due_on']) ?><?php else: ?><?= (int)$c['open_count'] ?> em aberto<?php endif; ?></span>
        <?php if ($c['overdue_cents'] > 0): ?><span class="app-hero__badge"><?= F::money($c['overdue_cents']) ?> vencido</span><?php endif; ?>
    </a>
<?php endif; ?>
<?php if (isset($summary['deliveries_waiting'])): $w = (int)$summary['deliveries_waiting']; ?>
    <a class="app-card app-tile" href="<?= app_url('/encomendas') ?>">
        <span class="app-tile__icon<?= $w > 0 ? ' app-tile__icon--blue' : '' ?>"><svg aria-hidden="true"><use href="#i-box"></use></svg></span>
        <span class="app-stat"><span class="app-stat__label">Encomendas na portaria</span><strong class="app-stat__value"><?= $w ?></strong><span class="app-stat__hint"><?= $w > 0 ? 'Aguardando retirada' : 'Nenhuma aguardando' ?></span></span>
    </a>
<?php endif; ?>
<?php if (isset($summary['visits_today'])): ?>
    <a class="app-card app-tile" href="<?= app_url('/visitantes') ?>">
        <span class="app-tile__icon app-tile__icon--purple"><svg aria-hidden="true"><use href="#i-user-check"></use></svg></span>
        <span class="app-stat"><span class="app-stat__label">Visitas previstas hoje</span><strong class="app-stat__value"><?= (int)$summary['visits_today'] ?></strong><span class="app-stat__hint">Avise a portaria pelo app</span></span>
    </a>
<?php endif; ?>
</div>

<?php if (!empty($summary['assemblies'])): ?>
    <section class="app-section">
        <div class="app-section__head"><h2>Assembleias</h2><a href="<?= app_url('/assembleias') ?>">Ver todas</a></div>
        <ul class="app-items">
        <?php foreach ($summary['assemblies'] as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']);
            $this->insert('partials/item', ['date' => $a['first_call_at'], 'title' => $a['title'], 'lines' => [F::dateTime($a['first_call_at'])],
                'href' => app_url('/assembleias/' . $a['id']), 'badge' => [$label, $tone]]);
        endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (isset($summary['next_reservations'])): ?>
    <section class="app-section">
        <div class="app-section__head"><h2>Próximas reservas</h2><a href="<?= app_url('/reservas') ?>">Reservar</a></div>
        <?php if ($summary['next_reservations'] === []): ?>
            <?php $this->insert('partials/empty', ['icon' => 'calendar', 'text' => 'Nenhuma reserva marcada.', 'action' => ['Reservar uma área', app_url('/reservas')]]) ?>
        <?php else: ?>
        <ul class="app-items">
        <?php foreach ($summary['next_reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']);
            $this->insert('partials/item', ['date' => $r['reserved_on'], 'title' => $r['area'], 'lines' => [$r['starts_time'] ? $r['starts_time'] . ' às ' . $r['ends_time'] : 'Dia todo'],
                'href' => app_url('/reservas'), 'badge' => [$label, $tone]]);
        endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (isset($summary['announcements'])): ?>
    <section class="app-section">
        <div class="app-section__head"><h2>Comunicados recentes</h2><a href="<?= app_url('/comunicados') ?>">Ver todos</a></div>
        <?php if ($summary['announcements'] === []): ?>
            <?php $this->insert('partials/empty', ['icon' => 'megaphone', 'text' => 'Nenhum comunicado por enquanto.']) ?>
        <?php else: ?>
        <ul class="app-items">
        <?php foreach ($summary['announcements'] as $n):
            $this->insert('partials/item', ['icon' => 'megaphone', 'title' => $n['subject'], 'lines' => [F::date($n['published_at'])], 'href' => app_url('/comunicados#c' . $n['id'])]);
        endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
