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
        <div class="app-card app-card--list"><ul class="app-list">
        <?php foreach ($summary['assemblies'] as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']); ?>
            <li><a class="app-row" href="<?= app_url('/assembleias/' . $a['id']) ?>">
                <span class="app-row__lead"><span class="app-mini-icon"><svg aria-hidden="true"><use href="#i-users"></use></svg></span><span><strong><?= $this->e($a['title']) ?></strong><small><?= F::dateTime($a['first_call_at']) ?></small></span></span>
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
            </a></li>
        <?php endforeach; ?>
        </ul></div>
    </section>
<?php endif; ?>

<?php if (isset($summary['next_reservations'])): ?>
    <section class="app-section">
        <div class="app-section__head"><h2>Próximas reservas</h2><a href="<?= app_url('/reservas') ?>">Reservar</a></div>
        <?php if ($summary['next_reservations'] === []): ?>
            <div class="app-card app-empty"><p>Nenhuma reserva marcada. <a href="<?= app_url('/reservas') ?>">Reservar uma área</a></p></div>
        <?php else: ?>
        <div class="app-card app-card--list"><ul class="app-list">
        <?php foreach ($summary['next_reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']); ?>
            <li><div class="app-row">
                <span class="app-row__lead"><span class="app-mini-icon"><svg aria-hidden="true"><use href="#i-calendar"></use></svg></span><span><strong><?= $this->e($r['area']) ?></strong><small><?= F::date($r['reserved_on']) ?><?= $r['starts_time'] ? ' · ' . $r['starts_time'] . ' às ' . $r['ends_time'] : '' ?></small></span></span>
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
            </div></li>
        <?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (isset($summary['announcements'])): ?>
    <section class="app-section">
        <div class="app-section__head"><h2>Comunicados recentes</h2><a href="<?= app_url('/comunicados') ?>">Ver todos</a></div>
        <?php if ($summary['announcements'] === []): ?>
            <div class="app-card app-empty"><p>Nenhum comunicado por enquanto.</p></div>
        <?php else: ?>
        <div class="app-card app-card--list"><ul class="app-list">
        <?php foreach ($summary['announcements'] as $n): ?>
            <li><a class="app-row" href="<?= app_url('/comunicados#c' . $n['id']) ?>">
                <span class="app-row__lead"><span class="app-mini-icon"><svg aria-hidden="true"><use href="#i-megaphone"></use></svg></span><span><strong><?= $this->e($n['subject']) ?></strong><small><?= F::date($n['published_at']) ?></small></span></span>
                <span class="app-row__go" aria-hidden="true">›</span>
            </a></li>
        <?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
