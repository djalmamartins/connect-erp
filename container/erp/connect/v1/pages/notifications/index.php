<?php
/**
 * Fila de avisos (CN-005, ADR 0055 e 0072): avisos da outbox no condomínio da barra superior, com
 * situação, tentativas e modo de entrega; ?aviso={id} mostra a trilha com os endereços mascarados.
 * Reenviar é POST com CSRF (NotificationQueue::retry), só para aviso com falha ou cancelado.
 * @var array $currentPage @var ?array{data:list<array>, total:int} $queue @var ?string $queueStatus
 * @var string $queueMode @var ?array $queueDetail @var ?string $queueError @var bool $canRetry
 */
use Source\Controllers\Erp\Connect\NotificationQueue;

$this->layout('default');
$route = erp_url($currentPage['route']);
$rows = $queue['data'] ?? [];
$stamp = static fn (?string $v): string => $v ? date('d/m/Y H:i', (int)strtotime($v)) : '—';
$tones = ['pending' => 'info', 'processing' => 'info', 'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'warning'];
$names = array_column($condoOptions ?? [], 'name', 'id');
$link = static function (array $changes) use ($route, $queueStatus): string {
    $params = array_filter(array_merge(['situacao' => $queueStatus], $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $route . ($params === [] ? '' : '?' . http_build_query($params));
};
$retryable = static fn (array $n): bool => in_array($n['status'], ['failed', 'cancelled'], true);

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Avisos que o ERP manda por e-mail, como a chamada da lista de espera. Cada aviso tenta até 5 vezes; o que falhou pode ser reenviado daqui.',
    'pageActions' => '',
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="status"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>
<?php if ($queueError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($queueError) ?></div></div>
<?php endif; ?>

<div class="erp-alert<?= $queueMode === 'production' ? ' erp-alert--warning' : '' ?>" role="note" data-queue-mode="<?= $this->e($queueMode) ?>"><?= $icon('info') ?><div><strong>Modo de entrega: <?= $this->e(NotificationQueue::MODES[$queueMode] ?? $queueMode) ?></strong><?= match ($queueMode) {
    'log' => 'Os avisos ficam só no registro do sistema. Nenhum e-mail sai.',
    'sandbox' => 'Os e-mails vão para a caixa de teste, com "[TESTE]" no assunto. Nenhum morador recebe.',
    default => 'Os e-mails vão para os moradores.',
} ?></div></div>

<?php if ($queue === null): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('bell') ?></span><strong>Fila indisponível</strong><p>Sem conexão com o banco de dados, a fila não pode ser lida.</p></div></section>
<?php else: ?>
<div class="erp-grid<?= $queueDetail !== null ? ' erp-grid--main' : '' ?>">
    <section class="erp-card" aria-labelledby="queue-title">
        <div class="erp-card__head">
            <div><h2 id="queue-title">Avisos</h2><p><?= (int)$queue['total'] ?> aviso(s)<?= $queue['total'] >= 200 ? ', os 200 mais recentes' : '' ?>.</p></div>
        </div>
        <nav class="erp-disk-folders" aria-label="Situação">
            <a class="erp-disk-folder<?= $queueStatus === null ? ' is-active' : '' ?>" href="<?= $this->e($link(['situacao' => null])) ?>"<?= $queueStatus === null ? ' aria-current="page"' : '' ?>>Todos</a>
            <?php foreach (NotificationQueue::STATUSES as $key => $label): ?>
                <a class="erp-disk-folder<?= $queueStatus === $key ? ' is-active' : '' ?>" href="<?= $this->e($link(['situacao' => $key])) ?>"<?= $queueStatus === $key ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('bell') ?></span><strong>Nenhum aviso<?= $queueStatus !== null ? ' nesta situação' : '' ?></strong><p>Os avisos aparecem aqui quando o ERP gera um, como ao chamar a lista de espera.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="queue-title" tabindex="0"><table class="erp-table">
                <thead><tr><th>Aviso</th><th>Situação</th><th>Atualizado</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $n): $id = (int)$n['id']; ?><tr<?= ($queueDetail['id'] ?? null) === $id ? ' class="is-selected"' : '' ?>>
                    <td class="erp-queue-name"><strong><?= $this->e(NotificationQueue::EVENTS[$n['event_type']] ?? $n['event_type']) ?></strong> <span class="erp-muted">nº <?= $id ?></span><br><span class="erp-muted"><?= $this->e((string)($names[$n['condominium_id']] ?? 'Condomínio nº ' . $n['condominium_id'])) ?></span></td>
                    <td><span class="erp-badge erp-badge--<?= $tones[$n['status']] ?? 'plain' ?>"><?= $this->e(NotificationQueue::STATUSES[$n['status']] ?? $n['status']) ?></span><br><span class="erp-muted"><?= (int)$n['attempts'] ?> de 5 tentativas</span><?php if ($n['last_error']): ?><br><span class="erp-muted erp-queue-error"><?= $this->e((string)$n['last_error']) ?></span><?php endif; ?></td>
                    <td class="erp-nowrap"><?= $this->e($stamp($n['processed_at'] ?? $n['created_at'])) ?><?php if ($n['status'] === 'pending' && $n['available_at']): ?><br><span class="erp-muted">próxima <?= $this->e($stamp($n['available_at'])) ?></span><?php endif; ?></td>
                    <td class="is-num"><div class="erp-table__actions">
                        <a class="erp-btn erp-btn--sm" href="<?= $this->e($link(['aviso' => $id])) ?>">Trilha</a>
                        <?php if ($canRetry && $retryable($n)): ?><form method="post" action="<?= $this->e($route . '/' . $id . '/reenviar') ?>" data-erp-confirm="Reenviar o aviso nº <?= $id ?>? Ele volta para a fila com as tentativas zeradas."><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--sm erp-btn--primary">Reenviar</button></form><?php endif; ?>
                    </div></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($queueDetail !== null): $d = $queueDetail; ?>
        <aside class="erp-card" aria-labelledby="queue-detail-title" data-queue-detail>
            <div class="erp-card__head"><div><h2 id="queue-detail-title">Aviso nº <?= (int)$d['id'] ?></h2><p><?= $this->e(NotificationQueue::EVENTS[$d['event_type']] ?? $d['event_type']) ?> · criado em <?= $this->e($stamp($d['created_at'])) ?></p></div></div>
            <dl class="erp-queue-facts">
                <dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $tones[$d['status']] ?? 'plain' ?>"><?= $this->e(NotificationQueue::STATUSES[$d['status']] ?? $d['status']) ?></span></dd>
                <dt>Tentativas</dt><dd><?= (int)$d['attempts'] ?> de 5</dd>
                <dt>Modo</dt><dd><?= $this->e($d['delivery_mode'] !== null ? (NotificationQueue::MODES[$d['delivery_mode']] ?? $d['delivery_mode']) : '—') ?></dd>
                <dt>Destinatários</dt><dd><?= (int)$d['recipients_count'] ?></dd>
                <?php if ($d['last_error']): ?><dt>Último erro</dt><dd><?= $this->e((string)$d['last_error']) ?></dd><?php endif; ?>
            </dl>
            <h3 class="erp-panel-subtitle">Entregas</h3>
            <?php if ($d['deliveries'] === []): ?>
                <p class="erp-field__hint">Nenhuma entrega registrada ainda.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($d['deliveries'] as $e): ?>
                        <li><span class="erp-list__icon"><?= $icon('user', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e((string)$e['recipient_masked']) ?></strong><small><?= $this->e(NotificationQueue::MODES[$e['delivery_mode']] ?? (string)$e['delivery_mode']) ?><?= $e['sent_to'] !== null ? ' · enviado para ' . $this->e((string)$e['sent_to']) : '' ?><?= $e['mail_queue_id'] ? ' · fila de e-mail nº ' . (int)$e['mail_queue_id'] : '' ?></small></div><span class="erp-list__meta"><?= $this->e($stamp($e['created_at'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canRetry && $retryable($d)): ?>
                <form method="post" action="<?= $this->e($route . '/' . (int)$d['id'] . '/reenviar') ?>" data-erp-confirm="Reenviar o aviso nº <?= (int)$d['id'] ?>? Ele volta para a fila com as tentativas zeradas." style="margin-top:16px"><?= csrf_input() ?><button type="submit" class="erp-btn erp-btn--primary erp-btn--block">Reenviar aviso</button></form>
            <?php endif; ?>
            <a class="erp-btn erp-btn--block" style="margin-top:8px" href="<?= $this->e($link(['aviso' => null])) ?>">Fechar a trilha</a>
        </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
