<?php
/**
 * Meu Dia (CN-010, ADR 0630): agenda do dia e da semana, o que falta agendar e o painel da visita.
 * @var array<string, mixed> $day MyDay::day()
 * @var ?array<string, mixed> $panel MyDay::visitPanel()
 * @var string $view dia|semana
 * @var list<array{id:int,name:string}> $team vazio quando a pessoa só vê o próprio dia
 * @var list<array> $condominiums
 * @var ?array{type:string,text:string} $flash
 * @var array<string,string> $errors @var array<string,mixed> $old
 * @var ?array<string,mixed> $schedule chamado escolhido em "Agendar"
 * @var string $longDate @var string $base @var bool $self
 * @var ?bool $sacPublic null quando a pessoa não atende chamados (ADR 0631)
 */
use Source\Services\Erp\MyDay;

$this->layout('default');
$asset = static function (string $path): string {
    $file = dirname(__DIR__, 2) . $path;
    return themeErp($path) . '?v=' . (is_file($file) ? filemtime($file) : 0);
};
$date = new DateTimeImmutable($day['date']);
$personId = (int)$day['person']['id'];
$link = static function (array $params) use ($base, $day, $self, $personId, $view): string {
    $query = array_filter($params + ['data' => $day['date'], 'pessoa' => $self ? null : $personId, 'visao' => $view === 'semana' ? 'semana' : null],
        static fn ($v): bool => $v !== null && $v !== '');
    return erp_url($base . ($query !== [] ? '?' . http_build_query($query) : ''));
};
$hours = static fn (int $minutes): string => intdiv($minutes, 60) . 'h' . ($minutes % 60 ? sprintf('%02d', $minutes % 60) . 'min' : '');
$fmt = static fn (string $ymd): string => date('d/m', strtotime($ymd));
$error = static fn (string $key): string => (string)($errors[$key] ?? '');
$value = static fn (string $key, $default = '') => $old[$key] ?? $default;
$schedule ??= null;
$typeTone = ['meeting' => 'info', 'task' => 'brand', 'deadline' => 'warning', 'support' => 'danger'];
$back = static function () use ($day, $self, $personId, $view): string {
    return csrf_input() . '<input type="hidden" name="back_date" value="' . htmlspecialchars($day['date'], ENT_QUOTES) . '">'
        . ($self ? '' : '<input type="hidden" name="back_person" value="' . $personId . '">')
        . ($view === 'semana' ? '<input type="hidden" name="back_view" value="semana">' : '');
};
$summary = $day['summary'];
?>
<link rel="stylesheet" href="<?= $asset('/assets/css/my-day.css') ?>">
<?php if (!empty($flash)): ?>
    <div class="erp-alert erp-alert--<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> md-flash" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $this->e($flash['text']) ?></div>
<?php endif; ?>
<?php ob_start(); ?>
    <a class="erp-btn" href="<?= $link(['data' => $day['today']]) ?>">Hoje</a>
    <a class="erp-btn md-icon-btn" href="<?= $link(['data' => $date->modify($view === 'semana' ? '-7 days' : '-1 day')->format('Y-m-d')]) ?>" aria-label="<?= $view === 'semana' ? 'Semana anterior' : 'Dia anterior' ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?></a>
    <a class="erp-btn md-icon-btn" href="<?= $link(['data' => $date->modify($view === 'semana' ? '+7 days' : '+1 day')->format('Y-m-d')]) ?>" aria-label="<?= $view === 'semana' ? 'Próxima semana' : 'Próximo dia' ?>"><?= $icon('chevron-right', 'erp-icon--sm') ?></a>
    <?php if ($team !== []): ?>
        <form class="md-person" method="get" action="<?= erp_url($base) ?>">
            <input type="hidden" name="data" value="<?= $this->e($day['date']) ?>">
            <label class="erp-sr-only" for="md-person">Ver o dia de</label>
            <select class="erp-select" id="md-person" name="pessoa">
                <?php foreach ($team as $member): ?>
                    <option value="<?= (int)$member['id'] ?>"<?= (int)$member['id'] === $personId ? ' selected' : '' ?>><?= $this->e($member['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="erp-btn erp-btn--sm" type="submit">Ver</button>
        </form>
    <?php endif; ?>
    <button class="erp-btn erp-btn--primary" type="button" data-erp-modal-open="md-new"><?= $icon('plus', 'erp-icon--sm') ?>Novo compromisso</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $self ? 'Agenda' : 'Agenda de ' . $day['person']['name'],
    'pageTitle' => 'Meu Dia',
    'pageLead' => $longDate,
    'pageActions' => $actions,
]);
?>

<section class="md-kpis" aria-label="Resumo do dia">
    <?php foreach ([
        ['Compromissos', (string)$summary['commitments'], $summary['commitment_minutes'] > 0 ? $hours($summary['commitment_minutes']) . ' marcados' : 'nada marcado', 'calendar', ''],
        ['Para agendar', (string)$summary['to_schedule'], 'chamados com você', 'clock', $summary['to_schedule'] > 0 ? 'warning' : ''],
        ['Aguardando cliente', (string)$summary['waiting'], 'esperando resposta', 'users', 'info'],
        ['Atrasados', (string)$summary['overdue'], 'chamados e vistorias', 'alert', $summary['overdue'] > 0 ? 'danger' : ''],
        ['Vistorias da semana', (string)$summary['week_visits'], $summary['week_condominiums'] . ' ' . ($summary['week_condominiums'] === 1 ? 'condomínio' : 'condomínios'), 'building', ''],
    ] as $i => [$label, $number, $meta, $kpiIcon, $tone]):
        $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => $number, 'meta' => $meta, 'icon' => $kpiIcon, 'tone' => $tone, 'trend' => null], 'delay' => $i * 40]);
    endforeach; ?>
</section>

<nav class="md-week" aria-label="Agenda da semana">
    <?php foreach ($day['week'] as $weekDay): ?>
        <a class="md-week__day<?= $weekDay['today'] ? ' is-today' : '' ?><?= $weekDay['selected'] ? ' is-selected' : '' ?>" href="<?= $link(['data' => $weekDay['date']]) ?>"<?= $weekDay['selected'] ? ' aria-current="date"' : '' ?>
           aria-label="<?= $this->e($weekDay['weekday'] . ' ' . $weekDay['day'] . ($weekDay['today'] ? ', hoje' : '') . ', ' . $weekDay['count'] . ($weekDay['count'] === 1 ? ' item' : ' itens')) ?>">
            <small><?= $this->e($weekDay['weekday']) ?></small>
            <strong><?= (int)$weekDay['day'] ?></strong>
            <span class="md-week__dot"<?= $weekDay['count'] === 0 ? ' hidden' : '' ?>><?= (int)$weekDay['count'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="md-grid">
    <section class="erp-card md-col" aria-labelledby="md-agenda-title">
        <div class="md-col__head">
            <h2 id="md-agenda-title">Agenda</h2>
            <div class="erp-tabs md-tabs" role="tablist">
                <a role="tab" aria-selected="<?= $view === 'dia' ? 'true' : 'false' ?>" href="<?= $link(['visao' => null]) ?>">Dia</a>
                <a role="tab" aria-selected="<?= $view === 'semana' ? 'true' : 'false' ?>" href="<?= erp_url($base . '?' . http_build_query(array_filter(['data' => $day['date'], 'pessoa' => $self ? null : $personId, 'visao' => 'semana']))) ?>">Semana</a>
            </div>
        </div>
        <?php if ($view === 'semana'): ?>
            <?php $this->insert('pages/my-day/partials/week', ['day' => $day, 'link' => $link, 'fmt' => $fmt]); ?>
        <?php else: ?>
            <?php if ($day['day_visits'] !== [] || $day['day_deadlines'] !== []): ?>
                <ul class="md-allday" aria-label="O dia todo">
                    <?php foreach ($day['day_visits'] as $visit): ?>
                        <li class="md-allday__item md-allday__item--visit">
                            <?= $icon('building', 'erp-icon--sm') ?>
                            <a href="<?= $link(['visita' => $visit['condominium_id']]) ?>"><strong>Vistoria <?= $this->e(mb_strtolower($visit['frequency_label'])) ?> – <?= $this->e($visit['condominium']) ?></strong></a>
                            <?php if ($visit['overdue']): ?><span class="erp-badge erp-badge--danger">Atrasada desde <?= $fmt($visit['due_on']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($day['day_deadlines'] as $ticket): ?>
                        <li class="md-allday__item">
                            <?= $icon('clock', 'erp-icon--sm') ?>
                            <a href="<?= erp_url('/operacional/chamados/' . $ticket['id']) ?>"><strong>Prazo: <?= $this->e($ticket['subject']) ?></strong></a>
                            <small><?= $this->e($ticket['protocol']) ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($day['events'] === []): ?>
                <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'calendar', 'emptyTitle' => 'Nada marcado neste dia',
                    'emptyText' => 'Use "Novo compromisso" ou "Agendar" num chamado da lista ao lado para montar o dia.']); ?>
            <?php else: ?>
                <ol class="md-timeline">
                    <?php foreach ($day['events'] as $event):
                        $isNow = $day['date'] === $day['today'] && $event['status'] === 'scheduled' && date('H:i') >= $event['start'] && date('H:i') < ($event['end'] ?? $event['start']); ?>
                        <li class="md-event md-event--<?= $this->e($typeTone[$event['type']] ?? 'info') ?> md-event--<?= $this->e($event['status']) ?><?= $isNow ? ' is-now' : '' ?>">
                            <time class="md-event__time" datetime="<?= $this->e($event['starts_at']) ?>"><?= $this->e($event['start']) ?><?= $event['end'] ? '<small>' . $this->e($event['end']) . '</small>' : '' ?></time>
                            <div class="md-event__body">
                                <strong><?= $this->e($event['title']) ?></strong>
                                <small>
                                    <?= $this->e($event['type_label']) ?><?= $event['condominium'] ? ' · ' . $this->e($event['condominium']) : '' ?>
                                    <?php if ($event['ticket_id']): ?> · <a href="<?= erp_url('/operacional/chamados/' . $event['ticket_id']) ?>">Chamado <?= $this->e((string)$event['protocol']) ?></a><?php endif; ?>
                                    <?php if ($event['status'] === 'completed'): ?> · <span class="erp-badge erp-badge--success">Feito</span><?php elseif ($event['status'] === 'cancelled'): ?> · <span class="erp-badge erp-badge--plain">Cancelado</span><?php endif; ?>
                                </small>
                            </div>
                            <div class="md-event__actions">
                                <?php if ($event['status'] === 'scheduled'): ?>
                                    <form method="post" action="<?= erp_url($base . '/compromissos/' . $event['id'] . '/situacao') ?>"><?= $back() ?><input type="hidden" name="status" value="completed"><button class="erp-btn erp-btn--sm" type="submit" aria-label="Concluir <?= $this->e($event['title']) ?>"><?= $icon('check', 'erp-icon--sm') ?></button></form>
                                    <form method="post" action="<?= erp_url($base . '/compromissos/' . $event['id'] . '/situacao') ?>"><?= $back() ?><input type="hidden" name="status" value="cancelled"><button class="erp-btn erp-btn--sm erp-btn--ghost" type="submit" aria-label="Cancelar <?= $this->e($event['title']) ?>"><?= $icon('close', 'erp-icon--sm') ?></button></form>
                                <?php else: ?>
                                    <form method="post" action="<?= erp_url($base . '/compromissos/' . $event['id'] . '/situacao') ?>"><?= $back() ?><input type="hidden" name="status" value="scheduled"><button class="erp-btn erp-btn--sm erp-btn--ghost" type="submit">Reabrir</button></form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($summary['commitments'] > 0): $pct = (int)round($summary['done'] * 100 / $summary['commitments']); ?>
                <div class="md-progress" aria-label="Feito no dia">
                    <span class="md-progress__bar"><span style="width: <?= $pct ?>%"></span></span>
                    <small><?= (int)$summary['done'] ?> de <?= (int)$summary['commitments'] ?> feitos (<?= $pct ?>%)</small>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="erp-card md-col" aria-labelledby="md-todo-title">
        <div class="md-col__head"><h2 id="md-todo-title">Para agendar <span class="erp-badge erp-badge--plain"><?= count($day['to_schedule']) ?></span></h2></div>
        <?php if ($day['to_schedule'] === []): ?>
            <p class="erp-muted md-none">Nenhum chamado esperando data. Quando alguém atribuir um chamado a <?= $self ? 'você' : 'esta pessoa' ?>, ele aparece aqui.</p>
        <?php else: ?>
            <ul class="md-list">
                <?php foreach ($day['to_schedule'] as $ticket):
                    $tagTone = ['Urgente' => 'danger', 'Importante' => 'warning', 'Rotina' => 'info'][$ticket['tag']] ?? 'plain'; ?>
                    <li class="md-task">
                        <div>
                            <a href="<?= erp_url('/operacional/chamados/' . $ticket['id']) ?>"><strong><?= $this->e($ticket['subject']) ?></strong></a>
                            <small><?= $this->e($ticket['condominium'] ?? 'Sem condomínio') ?> · <?= $this->e($ticket['protocol']) ?></small>
                            <span class="erp-badge erp-badge--<?= $tagTone ?>"><?= $this->e($ticket['tag']) ?></span>
                        </div>
                        <div class="md-task__side">
                            <small class="<?= $ticket['overdue'] ? 'md-late' : '' ?>">Prazo: <?= $ticket['overdue'] ? 'atrasado (' . $fmt($ticket['due_on']) . ')' : ($ticket['due_today'] ? 'hoje' : $fmt($ticket['due_on'])) ?></small>
                            <a class="erp-btn erp-btn--sm" href="<?= $link(['agendar' => $ticket['id']]) ?>#novo-compromisso">Agendar</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 class="md-subtitle">Aguardando cliente <span class="erp-badge erp-badge--plain"><?= count($day['waiting']) ?></span></h3>
        <?php if ($day['waiting'] === []): ?>
            <p class="erp-muted md-none">Nenhum chamado esperando resposta.</p>
        <?php else: ?>
            <ul class="md-list">
                <?php foreach ($day['waiting'] as $ticket): ?>
                    <li class="md-task"><div><a href="<?= erp_url('/operacional/chamados/' . $ticket['id']) ?>"><strong><?= $this->e($ticket['subject']) ?></strong></a><small><?= $this->e($ticket['condominium'] ?? 'Sem condomínio') ?> · <?= $this->e($ticket['protocol']) ?></small></div></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 class="md-subtitle">Próximas vistorias</h3>
        <?php if ($day['visits'] === []): ?>
            <p class="erp-muted md-none">Nenhum plano de vistoria com <?= $self ? 'você' : 'esta pessoa' ?> como responsável nos próximos 14 dias.</p>
        <?php else: ?>
            <ul class="md-list">
                <?php foreach ($day['visits'] as $visit): ?>
                    <li class="md-task">
                        <div><a href="<?= $link(['visita' => $visit['condominium_id']]) ?>"><strong><?= $this->e($visit['condominium']) ?></strong></a><small><?= $this->e($visit['template']) ?> · <?= $this->e($visit['frequency_label']) ?></small></div>
                        <div class="md-task__side"><small class="<?= $visit['overdue'] ? 'md-late' : '' ?>"><?= $visit['overdue'] ? 'Atrasada (' . $fmt($visit['due_on']) . ')' : MyDay::weekday(new DateTimeImmutable($visit['due_on'])) . ', ' . $fmt($visit['due_on']) ?></small></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($day['deadlines'] !== []): ?>
            <h3 class="md-subtitle">Documentos e vencimentos</h3>
            <ul class="md-list">
                <?php foreach ($day['deadlines'] as $item): ?>
                    <li class="md-task">
                        <div><a href="<?= erp_url('/condominios/' . $item['condominium_id'] . '/checklist') ?>"><strong><?= $this->e($item['title']) ?></strong></a><small><?= $this->e($item['condominium']) ?></small></div>
                        <div class="md-task__side"><small class="<?= $item['days'] < 0 ? 'md-late' : '' ?>"><?= $this->e($item['label']) ?></small></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="erp-card md-col md-visit" aria-labelledby="md-visit-title">
        <?php if ($panel === null): ?>
            <div class="md-col__head"><h2 id="md-visit-title">Painel da visita</h2></div>
            <p class="erp-muted">Escolha uma vistoria em "Próximas vistorias" para ver o que acompanhar no condomínio: chamados abertos, documentos que vencem e as pendências da última visita.</p>
        <?php else: $condo = $panel['condominium']; ?>
            <div class="md-col__head">
                <div>
                    <span class="erp-eyebrow">Visita</span>
                    <h2 id="md-visit-title"><?= $this->e($condo['name']) ?></h2>
                    <?php if ($condo['address'] !== ''): ?><small class="erp-muted"><?= $this->e($condo['address']) ?></small><?php endif; ?>
                </div>
            </div>
            <h3 class="md-subtitle">O que acompanhar</h3>
            <ul class="md-follow">
                <li><a href="<?= erp_url('/operacional/chamados?condominio=' . $condo['id']) ?>"><?= $icon('alert', 'erp-icon--sm') ?><span><strong>Chamados abertos</strong><small><?= $panel['urgent'] ?> urgentes ou importantes</small></span><em><?= count($panel['tickets']) ?></em></a></li>
                <li><a href="<?= erp_url('/condominios/' . $condo['id'] . '/checklist') ?>"><?= $icon('file', 'erp-icon--sm') ?><span><strong>Documentos e vencimentos</strong><small><?= $panel['documents'] !== [] ? $this->e($panel['documents'][0]['title'] . ': ' . mb_strtolower($panel['documents'][0]['label'])) : 'nada vencendo em 45 dias' ?></small></span><em><?= count($panel['documents']) ?></em></a></li>
                <li><a href="<?= erp_url('/operacional/chamados?condominio=' . $condo['id']) ?>"><?= $icon('clipboard', 'erp-icon--sm') ?><span><strong>Pendências da última vistoria</strong><small>problemas que viraram chamado</small></span><em><?= count($panel['pending_from_last_visit']) ?></em></a></li>
            </ul>
            <?php if ($panel['tickets'] !== []): ?>
                <ul class="md-list md-list--compact">
                    <?php foreach (array_slice($panel['tickets'], 0, 5) as $ticket): ?>
                        <li><a href="<?= erp_url('/operacional/chamados/' . $ticket['id']) ?>"><?= $this->e($ticket['subject']) ?></a> <span class="erp-badge erp-badge--<?= in_array($ticket['priority'], ['urgent', 'high'], true) ? 'danger' : 'plain' ?>"><?= $this->e($ticket['priority_label']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h3 class="md-subtitle">Última visita</h3>
            <?php if ($panel['last_visit'] === null): ?>
                <p class="erp-muted md-none">Nenhuma vistoria concluída ainda.</p>
            <?php else: $lastVisit = $panel['last_visit']; ?>
                <p class="md-last"><span><?= date('d/m/Y', strtotime($lastVisit['completed_at'])) ?> · <?= $this->e($lastVisit['template']) ?><br><small><?= $lastVisit['issues'] ?> <?= $lastVisit['issues'] === 1 ? 'problema encontrado' : 'problemas encontrados' ?></small></span>
                    <a class="erp-btn erp-btn--sm" href="<?= erp_url('/operacional/vistorias/' . $lastVisit['id']) ?>">Ver registro</a></p>
            <?php endif; ?>
            <div class="md-visit__actions">
                <a class="erp-btn erp-btn--primary" href="<?= erp_url('/operacional/vistorias/nova?condominio=' . $condo['id']) ?>"><?= $icon('check', 'erp-icon--sm') ?>Iniciar visita</a>
                <a class="erp-btn" href="<?= erp_url('/operacional/chamados?condominio=' . $condo['id']) ?>#abrir"><?= $icon('plus', 'erp-icon--sm') ?>Abrir chamado</a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($self && isset($sacPublic)): ?>
    <form class="md-sac" method="post" action="<?= erp_url($base . '/sac') ?>">
        <?= $back() ?>
        <input type="hidden" name="public" value="<?= $sacPublic ? '0' : '1' ?>">
        <span class="md-sac__text"><?= $icon('users', 'erp-icon--sm') ?>
            <span><strong>Balão do SAC no site</strong><small><?= $sacPublic
                ? 'Você aparece como online (primeiro nome e foto) quando está no sistema, em dia útil e no horário.'
                : 'Você atende normalmente, mas não aparece no balão do site. Se quiser, autorize mostrar seu primeiro nome e foto.' ?></small></span></span>
        <button class="erp-btn erp-btn--sm<?= $sacPublic ? '' : ' erp-btn--primary' ?>" type="submit"><?= $sacPublic ? 'Não aparecer mais' : 'Autorizar aparecer' ?></button>
    </form>
<?php endif; ?>

<dialog class="erp-modal" id="md-new" aria-labelledby="md-new-title"<?= $errors !== [] || $schedule !== null ? ' data-erp-modal-auto' : '' ?>>
<form class="erp-form" id="novo-compromisso" method="post" action="<?= erp_url($base . '/compromissos') ?>" novalidate>
    <div class="erp-modal__head">
        <div><h2 id="md-new-title"><?= $schedule !== null ? 'Agendar chamado' : 'Novo compromisso' ?></h2><p class="erp-muted">Entra na agenda do dia e no Meu Dia de quem vai fazer.</p></div>
        <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
    </div>
    <?= $back() ?>
    <?php if ($schedule !== null): ?><input type="hidden" name="ticket_id" value="<?= (int)$schedule['id'] ?>"><?php endif; ?>
    <div class="erp-modal__body">
    <div class="erp-form__grid">
        <div class="erp-field erp-col-12">
            <label for="md-title">O que é <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <input class="erp-input" id="md-title" name="title" maxlength="180" required value="<?= $this->e((string)$value('title', $schedule['subject'] ?? '')) ?>"<?= $error('title') !== '' ? ' aria-invalid="true" aria-describedby="md-title-error"' : '' ?>>
            <span class="erp-field__error" id="md-title-error"><?= $this->e($error('title')) ?></span>
        </div>
        <div class="erp-field erp-col-4">
            <label for="md-date">Dia <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <input class="erp-input" type="date" id="md-date" name="date" required value="<?= $this->e((string)$value('date', $day['date'])) ?>">
            <span class="erp-field__error"><?= $this->e($error('date')) ?></span>
        </div>
        <div class="erp-field erp-col-4">
            <label for="md-start">Início <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <input class="erp-input" type="time" id="md-start" name="start" required value="<?= $this->e((string)$value('start', '09:00')) ?>">
            <span class="erp-field__error"><?= $this->e($error('start')) ?></span>
        </div>
        <div class="erp-field erp-col-4">
            <label for="md-end">Fim</label>
            <input class="erp-input" type="time" id="md-end" name="end" value="<?= $this->e((string)$value('end', '10:00')) ?>">
            <span class="erp-field__error"><?= $this->e($error('end')) ?></span>
        </div>
        <div class="erp-field erp-col-6">
            <label for="md-type">Tipo</label>
            <select class="erp-select" id="md-type" name="type">
                <?php foreach (MyDay::TYPES as $key => $label): ?><option value="<?= $key ?>"<?= $value('type', $schedule !== null ? 'support' : 'meeting') === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="erp-field erp-col-6">
            <label for="md-condo">Condomínio</label>
            <select class="erp-select" id="md-condo" name="condominium_id">
                <option value="">Nenhum (interno)</option>
                <?php $selectedCondo = (int)$value('condominium_id', $schedule['condominium_id'] ?? 0);
                foreach ($condominiums as $condominium): ?><option value="<?= (int)$condominium['id'] ?>"<?= $selectedCondo === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option><?php endforeach; ?>
            </select>
            <span class="erp-field__error"><?= $this->e($error('condominium_id')) ?></span>
        </div>
        <?php if ($team !== []): ?>
            <div class="erp-field erp-col-12">
                <label for="md-assignee">Para quem</label>
                <select class="erp-select" id="md-assignee" name="assigned_to">
                    <?php foreach ($team as $member): ?><option value="<?= (int)$member['id'] ?>"<?= (int)$value('assigned_to', $personId) === (int)$member['id'] ? ' selected' : '' ?>><?= $this->e($member['name']) ?></option><?php endforeach; ?>
                </select>
                <span class="erp-field__error"><?= $this->e($error('assigned_to')) ?></span>
            </div>
        <?php endif; ?>
        <div class="erp-field erp-col-12">
            <label for="md-notes">Observações</label>
            <textarea class="erp-textarea" id="md-notes" name="description" rows="3" maxlength="2000"><?= $this->e((string)$value('description')) ?></textarea>
        </div>
    </div>
    <?php if ($error('ticket_id') !== ''): ?><p class="erp-field__error"><?= $this->e($error('ticket_id')) ?></p><?php endif; ?>
    </div>
    <div class="erp-modal__foot">
        <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
        <button class="erp-btn erp-btn--primary" type="submit">Marcar</button>
    </div>
</form>
</dialog>
