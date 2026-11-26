<?php
/**
 * Meu Dia, visão Semana: o que cada dia da semana tem (compromissos, vistorias e prazos de chamado).
 * @var array<string, mixed> $day MyDay::day() @var callable $link @var callable $fmt
 */
$week = [];
foreach ($day['week'] as $weekDay) {
    $week[$weekDay['date']] = ['info' => $weekDay];
}
?>
<ol class="md-weeklist">
    <?php foreach ($week as $date => $entry):
        $events = array_values(array_filter($day['week_events'] ?? [], static fn (array $e): bool => substr($e['starts_at'], 0, 10) === $date && $e['status'] !== 'cancelled'));
        $visits = array_values(array_filter($day['visits'], static fn (array $v): bool => $v['due_on'] === $date));
        $deadlines = array_values(array_filter($day['to_schedule'], static fn (array $t): bool => $t['due_on'] === $date)); ?>
        <li class="md-weeklist__day<?= $entry['info']['today'] ? ' is-today' : '' ?>">
            <a class="md-weeklist__date" href="<?= $link(['data' => $date, 'visao' => null]) ?>"><strong><?= $this->e($entry['info']['weekday']) ?> <?= $fmt($date) ?></strong><?= $entry['info']['today'] ? ' <span class="erp-badge erp-badge--success">Hoje</span>' : '' ?></a>
            <?php if ($events === [] && $visits === [] && $deadlines === []): ?>
                <small class="erp-muted">Livre</small>
            <?php else: ?>
                <ul>
                    <?php foreach ($events as $event): ?><li><time><?= $this->e($event['start']) ?></time> <?= $this->e($event['title']) ?><?= $event['condominium'] ? ' · <small>' . $this->e($event['condominium']) . '</small>' : '' ?><?= $event['status'] === 'completed' ? ' <span class="erp-badge erp-badge--success">Feito</span>' : '' ?><?= !empty($event['overdue']) ? ' <span class="erp-badge erp-badge--danger">Atrasada</span>' : '' ?></li><?php endforeach; ?>
                    <?php foreach ($visits as $visit): ?><li><time>Dia</time> Vistoria – <?= $this->e($visit['condominium']) ?></li><?php endforeach; ?>
                    <?php foreach ($deadlines as $ticket): ?><li><time>Prazo</time> <?= $this->e($ticket['subject']) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
