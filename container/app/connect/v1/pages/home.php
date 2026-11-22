<?php
/** Início do app do morador (ADR 0302; revisão de 15/11): cartão da unidade, seis serviços e notificações. @var array $summary ResidentPortal::summary() @var array $link @var list<array> $feed ResidentFeed::notifications() @var ?string $seenAt */
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
// Seis serviços principais, dois por linha (revisão de 15/11, iguais aos do app de celular).
$on = static fn (string $feature): bool => in_array($feature, $features, true);
$actions = array_values(array_filter([
    $on('boletos') ? ['Boletos', app_url('/boletos'), 'receipt'] : null,
    $on('reservations') ? ['Reservas', app_url('/reservas'), 'calendar'] : null,
    $on('occurrences') ? ['Chamados', app_url('/ocorrencias'), 'alert'] : null,
    $on('visitors') ? ['Autorizar entrada', app_url('/visitantes'), 'user-check'] : null,
    $on('documents') ? ['Documentos', app_url('/documentos'), 'file'] : null,
    $on('accountability') ? ['Prestação de contas', app_url('/prestacao-de-contas'), 'chart'] : null,
]));
if ($actions !== []) {
    $this->insert('partials/actions', ['items' => $actions, 'label' => 'Principais serviços']);
}
$icons = ['charge' => 'receipt', 'delivery' => 'box', 'visit' => 'user-check', 'announcement' => 'megaphone', 'assembly' => 'users', 'reservation' => 'calendar', 'ticket' => 'chat'];
?>
<section class="app-section">
    <div class="app-section__head"><h2>Notificações</h2><a href="<?= app_url('/avisos') ?>">Ver todas</a></div>
    <?php if ($feed === []): ?>
        <?php $this->insert('partials/empty', ['icon' => 'bell', 'text' => 'Nenhum aviso agora. Tudo em dia.']) ?>
    <?php else: ?>
    <ul class="app-items">
    <?php foreach ($feed as $item):
        $this->insert('partials/item', ['icon' => $icons[$item['kind']] ?? 'bell', 'tone' => $item['tone'], 'title' => $item['title'],
            'lines' => [$item['detail'], F::date(substr($item['at'], 0, 10))], 'href' => app_url($item['path']),
            'flag' => \Source\Services\Resident\ResidentFeed::isNew($item, $seenAt) ? 'Novo' : null]);
    endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
