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

<?php $c = $summary['charges'] ?? null; ?>
    <section class="app-card app-hero app-wallet">
        <img class="app-wallet__mark" src="<?= themeApp('/assets/images/connect-symbol.png') ?>" alt="" aria-hidden="true" width="180" height="234">
        <div class="app-wallet__top">
            <?php if (count($links ?? []) > 1): ?>
                <details class="app-switch">
                    <summary><strong><?= $this->e($link['condominium_name']) ?></strong><svg aria-hidden="true"><use href="#i-chevron"></use></svg><span class="app-sr">Trocar de unidade</span></summary>
                    <form class="app-switch__menu" method="post" action="<?= app_url('/unidade') ?>">
                        <?= csrf_input() ?>
                        <?php foreach ($links as $item): $current = $item['resident_id'] === $link['resident_id']; ?>
                            <button type="submit" name="resident_id" value="<?= (int)$item['resident_id'] ?>"<?= $current ? ' aria-current="true"' : '' ?>>
                                <strong><?= $this->e($item['condominium_name']) ?></strong><small><?= $this->e($item['unit_label'] . ' · ' . $item['relationship_label']) ?></small>
                                <?php if ($current): ?><svg aria-hidden="true"><use href="#i-check"></use></svg><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                </details>
            <?php else: ?>
                <strong class="app-wallet__condo"><?= $this->e($link['condominium_name']) ?></strong>
            <?php endif; ?>
            <span class="app-wallet__pill"><svg aria-hidden="true"><use href="#i-home"></use></svg><?= $this->e($link['unit_label'] . ' · ' . $link['relationship_label']) ?></span>
        </div>
        <?php if ($c !== null): ?>
            <div class="app-wallet__label">Em aberto
                <button type="button" class="app-wallet__eye" data-app-eye aria-pressed="false" aria-label="Esconder valores"><svg class="app-eye-on" aria-hidden="true"><use href="#i-eye"></use></svg><svg class="app-eye-off" aria-hidden="true"><use href="#i-eye-off"></use></svg></button>
            </div>
            <a class="app-wallet__value" href="<?= app_url('/boletos') ?>"><span class="app-money"><?= F::money($c['open_cents']) ?></span><span class="app-money-hidden">R$ •••••</span></a>
            <a class="app-wallet__next" href="<?= app_url('/boletos') ?>"><?php if ($c['open_count'] === 0): ?>Nada em aberto. Obrigado!<?php elseif ($c['next_due_on']): ?>Próximo vencimento <?= F::date($c['next_due_on']) ?><?php else: ?><?= (int)$c['open_count'] ?> em aberto<?php endif; ?><svg aria-hidden="true"><use href="#i-chevron"></use></svg></a>
            <?php if ($c['overdue_cents'] > 0): ?><span class="app-hero__badge"><span class="app-money"><?= F::money($c['overdue_cents']) ?></span><span class="app-money-hidden">R$ •••</span> vencido</span><?php endif; ?>
            <?php if ($c['next_entry_id'] !== null): ?>
                <div class="app-wallet__actions">
                    <a class="app-wallet__btn app-wallet__btn--gold" href="<?= app_url('/boletos/' . $c['next_entry_id']) ?>"><svg aria-hidden="true"><use href="#i-file"></use></svg>2ª via do boleto</a>
                    <?php if ($c['next_code'] !== null): ?><button type="button" class="app-wallet__btn" data-app-copy="<?= $this->e($c['next_code']) ?>"><svg aria-hidden="true"><use href="#i-copy"></use></svg>Copiar código</button><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php
$quick = array_values(array_filter([
    in_array('reservations', $features, true) ? ['Reservar', '/reservas', 'calendar'] : null,
    in_array('occurrences', $features, true) ? ['Chamado', '/ocorrencias', 'alert'] : null,
    in_array('visitors', $features, true) ? ['Visitante', '/visitantes', 'user-check'] : null,
    in_array('documents', $features, true) ? ['Documentos', '/documentos', 'file'] : null,
]));
if ($quick !== []): ?>
    <nav class="app-quick" aria-label="Atalhos">
        <?php foreach ($quick as [$label, $path, $icon]): ?>
            <a href="<?= app_url($path) ?>"><span class="app-quick__icon" aria-hidden="true"><svg><use href="#i-<?= $icon ?>"></use></svg></span><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<div class="app-grid">
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
