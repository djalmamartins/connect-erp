<?php
/** Início do app do morador (ADR 0302). @var array $summary ResidentPortal::summary() @var array $link @var list<array> $menu */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$features = $summary['features'];
$first = explode(' ', trim((string)$link['name']))[0] ?? '';
?>
<section class="app-hello">
    <h1>Olá, <?= $this->e($first) ?></h1>
    <p><?= $this->e($link['relationship_label']) ?> · <?= $this->e($link['unit_label']) ?> · <?= $this->e($link['condominium_name']) ?></p>
</section>

<?php if ($features === []): ?>
    <div class="app-card app-empty">
        <h2>O aplicativo ainda está sendo preparado</h2>
        <p>O seu condomínio ainda não liberou as funções do aplicativo. Assim que a administração ligar, elas aparecem aqui.</p>
    </div>
<?php endif; ?>

<div class="app-grid">
<?php if (isset($summary['charges'])): $c = $summary['charges']; ?>
    <a class="app-card app-stat<?= $c['overdue_cents'] > 0 ? ' app-stat--danger' : '' ?>" href="<?= app_url('/boletos') ?>">
        <span class="app-stat__label">Boletos em aberto</span>
        <strong class="app-stat__value"><?= F::money($c['open_cents']) ?></strong>
        <span class="app-stat__hint"><?php if ($c['open_count'] === 0): ?>Nada em aberto. Obrigado!<?php elseif ($c['overdue_cents'] > 0): ?><?= F::money($c['overdue_cents']) ?> vencido<?php else: ?>Próximo vencimento <?= F::date($c['next_due_on']) ?><?php endif; ?></span>
    </a>
<?php endif; ?>
<?php if (isset($summary['deliveries_waiting'])): ?>
    <a class="app-card app-stat<?= $summary['deliveries_waiting'] > 0 ? ' app-stat--accent' : '' ?>" href="<?= app_url('/encomendas') ?>">
        <span class="app-stat__label">Encomendas na portaria</span>
        <strong class="app-stat__value"><?= (int)$summary['deliveries_waiting'] ?></strong>
        <span class="app-stat__hint"><?= $summary['deliveries_waiting'] > 0 ? 'Aguardando retirada' : 'Nenhuma aguardando' ?></span>
    </a>
<?php endif; ?>
<?php if (isset($summary['visits_today'])): ?>
    <a class="app-card app-stat" href="<?= app_url('/visitantes') ?>">
        <span class="app-stat__label">Visitas previstas hoje</span>
        <strong class="app-stat__value"><?= (int)$summary['visits_today'] ?></strong>
        <span class="app-stat__hint">Avise a portaria pelo app</span>
    </a>
<?php endif; ?>
</div>

<?php if (!empty($summary['assemblies'])): ?>
    <section class="app-section">
        <h2>Assembleias</h2>
        <?php foreach ($summary['assemblies'] as $a): [$label, $tone] = F::badge(F::ASSEMBLY, $a['status']); ?>
            <a class="app-card app-row" href="<?= app_url('/assembleias/' . $a['id']) ?>">
                <span><strong><?= $this->e($a['title']) ?></strong><small><?= F::dateTime($a['first_call_at']) ?></small></span>
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (isset($summary['next_reservations'])): ?>
    <section class="app-section">
        <h2>Próximas reservas</h2>
        <?php if ($summary['next_reservations'] === []): ?>
            <p class="app-muted">Nenhuma reserva marcada. <a href="<?= app_url('/reservas') ?>">Reservar uma área</a></p>
        <?php endif; ?>
        <?php foreach ($summary['next_reservations'] as $r): [$label, $tone] = F::badge(F::RESERVATION, $r['status']); ?>
            <div class="app-card app-row">
                <span><strong><?= $this->e($r['area']) ?></strong><small><?= F::date($r['reserved_on']) ?><?= $r['starts_time'] ? ' · ' . $r['starts_time'] . ' às ' . $r['ends_time'] : '' ?></small></span>
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (isset($summary['announcements'])): ?>
    <section class="app-section">
        <h2>Comunicados recentes</h2>
        <?php if ($summary['announcements'] === []): ?><p class="app-muted">Nenhum comunicado por enquanto.</p><?php endif; ?>
        <?php foreach ($summary['announcements'] as $n): ?>
            <a class="app-card app-row" href="<?= app_url('/comunicados#c' . $n['id']) ?>">
                <span><strong><?= $this->e($n['subject']) ?></strong><small><?= F::date($n['published_at']) ?></small></span>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
