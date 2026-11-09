<?php
/**
 * Chamado no ERP (CN-004, ADR 0142): situação, contexto e a conversa pública com o Help Desk.
 * Quem pediu lê as respostas da equipe e responde daqui; as notas internas do Help Desk não aparecem.
 * @var string $base
 * @var array $ticket TicketService::present()
 * @var list<array> $messages TicketService::conversation()
 * @var ?array{type:string,text:string} $flash
 * @var array<string,string> $errors @var array<string,mixed> $old
 * @var ?string $helpdeskUrl o chamado no Help Desk (só para quem atende)
 */
use Source\Services\Support\TicketService;

$this->layout('default');
$statusTone = ['open' => 'info', 'in_progress' => 'warning', 'waiting_customer' => 'warning', 'resolved' => 'success', 'closed' => 'plain'];
$priorityTone = ['low' => 'plain', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
$closed = in_array($ticket['status'], ['resolved', 'closed'], true);
$late = !$closed && strtotime($ticket['due_at']) < time();
$when = static fn (string $date): string => date('d/m/Y H:i', strtotime($date));
$statusHint = [
    'open' => 'Na fila do Help Desk, aguardando atendimento.',
    'in_progress' => 'A equipe está cuidando do chamado.',
    'waiting_customer' => 'A equipe precisa de uma resposta sua para seguir.',
    'resolved' => 'A equipe marcou como resolvido. Se algo ficou pendente, responda e o chamado é reaberto.',
    'closed' => 'Chamado encerrado. Uma nova mensagem reabre o atendimento.',
][$ticket['status']] ?? '';
ob_start(); ?>
    <a class="erp-btn" href="<?= erp_url($base) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Todos os chamados</a>
    <?php if ($helpdeskUrl !== null): ?><a class="erp-btn erp-btn--primary" href="<?= $this->e($helpdeskUrl) ?>"><?= $icon('help', 'erp-icon--sm') ?>Atender no Help Desk</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Chamado ' . $ticket['protocol'],
    'pageTitle' => $ticket['subject'],
    'pageLead' => $statusHint,
    'pageActions' => $actions,
]);
?>
<?php if (!empty($flash)): ?>
    <div class="erp-alert erp-alert--<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $this->e($flash['text']) ?></div>
<?php endif; ?>

<div class="erp-grid erp-grid--main erp-ticket">
    <div class="erp-stack">
        <section class="erp-card" id="conversa" aria-labelledby="tk-thread-title">
            <div class="erp-card__head"><div><h2 id="tk-thread-title">Conversa</h2><p>O pedido e as respostas trocadas com a equipe do Help Desk.</p></div></div>
            <ol class="erp-ticket-thread">
                <li class="erp-ticket-msg erp-ticket-msg--requester">
                    <header><strong><?= $this->e($ticket['requester']['name'] ?? 'Solicitante') ?></strong><small>Abriu o chamado · <?= $when($ticket['created_at']) ?></small></header>
                    <p><?= nl2br($this->e($ticket['message'])) ?></p>
                </li>
                <?php foreach ($messages as $message): ?>
                    <li class="erp-ticket-msg <?= $message['from_team'] ? 'erp-ticket-msg--team' : 'erp-ticket-msg--requester' ?>">
                        <header><strong><?= $this->e($message['author']) ?></strong><small><?= $message['from_team'] ? 'Equipe Connect' : 'Solicitante' ?> · <?= $when($message['created_at']) ?></small></header>
                        <p><?= nl2br($this->e($message['message'])) ?></p>
                        <?php if ($message['attachments'] !== []): ?>
                            <footer><?php foreach ($message['attachments'] as $file): ?><a href="<?= $this->e($file['url']) ?>" target="_blank" rel="noopener"><?= $icon('file', 'erp-icon--sm') ?><?= $this->e($file['name']) ?></a><?php endforeach; ?></footer>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($messages === []): ?>
                <p class="erp-ticket-wait"><?= $icon('info', 'erp-icon--sm') ?>A equipe ainda não respondeu. Você recebe um aviso no sino e por e-mail quando houver resposta.</p>
            <?php endif; ?>
        </section>

        <form class="erp-card erp-form" id="responder" method="post" action="<?= erp_url($base . '/' . (int)$ticket['id'] . '/responder') ?>" novalidate>
            <div class="erp-card__head"><div><h2><?= $closed ? 'Reabrir com uma mensagem' : 'Responder' ?></h2><p><?= $closed ? 'A mensagem reabre o chamado e volta para a fila do Help Desk.' : 'A mensagem vai para a equipe do Help Desk, que é avisada na hora.' ?></p></div></div>
            <?= csrf_input() ?>
            <div class="erp-field">
                <label for="tk-reply">Mensagem</label>
                <textarea class="erp-textarea" id="tk-reply" name="message" rows="4" required maxlength="10000"<?= isset($errors['message']) ? ' aria-invalid="true" aria-describedby="tk-reply-error"' : '' ?>><?= $this->e((string)($old['message'] ?? '')) ?></textarea>
                <span class="erp-field__error" id="tk-reply-error"><?= $this->e($errors['message'] ?? '') ?></span>
            </div>
            <div class="erp-form__actions">
                <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?><?= $closed ? 'Enviar e reabrir' : 'Enviar mensagem' ?></button>
            </div>
        </form>
    </div>

    <aside class="erp-stack">
        <section class="erp-card" aria-labelledby="tk-facts-title">
            <div class="erp-card__head"><div><h2 id="tk-facts-title">Detalhes</h2></div></div>
            <dl class="erp-detail-grid">
                <div><dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $statusTone[$ticket['status']] ?? 'plain' ?>"><?= $this->e(TicketService::STATUS_LABELS[$ticket['status']] ?? $ticket['status']) ?></span></dd></div>
                <div><dt>Prioridade</dt><dd><span class="erp-badge erp-badge--<?= $priorityTone[$ticket['priority']] ?? 'plain' ?>"><?= $this->e(TicketService::PRIORITY_LABELS[$ticket['priority']] ?? $ticket['priority']) ?></span></dd></div>
                <div><dt>Condomínio</dt><dd><?= $this->e($ticket['condominium']['name'] ?? 'Assunto geral') ?></dd></div>
                <div><dt>Unidade</dt><dd><?= $this->e($ticket['unit']['label'] ?? '—') ?></dd></div>
                <div><dt>Área</dt><dd><?= $this->e(TicketService::AREA_LABELS[$ticket['area']] ?? $ticket['area']) ?></dd></div>
                <div><dt>Origem</dt><dd><?= $this->e(TicketService::ORIGIN_LABELS[$ticket['origin']] ?? $ticket['origin']) ?></dd></div>
                <div><dt>Responsável</dt><dd><?= $this->e($ticket['assigned_to']['name'] ?? 'A definir') ?></dd></div>
                <div><dt>Prazo</dt><dd<?= $late ? ' style="color:var(--erp-danger)"' : '' ?>><?= $closed ? 'Finalizado' : $when($ticket['due_at']) . ($late ? ' · vencido' : '') ?></dd></div>
                <div><dt>Aberto em</dt><dd><?= $when($ticket['created_at']) ?></dd></div>
                <?php if ($ticket['resolved_at'] !== null): ?><div><dt>Resolvido em</dt><dd><?= $when($ticket['resolved_at']) ?></dd></div><?php endif; ?>
            </dl>
        </section>
        <section class="erp-card" aria-labelledby="tk-requester-title">
            <div class="erp-card__head"><div><h2 id="tk-requester-title">Quem pediu</h2></div></div>
            <dl class="erp-detail-grid erp-detail-grid--single">
                <div><dt>Nome</dt><dd><?= $this->e($ticket['requester']['name'] ?? '—') ?></dd></div>
                <?php if (!empty($ticket['requester']['email'])): ?><div><dt>E-mail</dt><dd><?= $this->e($ticket['requester']['email']) ?></dd></div><?php endif; ?>
                <?php if (!empty($ticket['requester']['phone'])): ?><div><dt>Telefone</dt><dd><?= $this->e($ticket['requester']['phone']) ?></dd></div><?php endif; ?>
            </dl>
        </section>
    </aside>
</div>
