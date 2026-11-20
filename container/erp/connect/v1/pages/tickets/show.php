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
 * @var bool $canAttend quem atende (support.manage): triagem, cronômetro, notas internas e histórico (ADR 0307)
 * @var array $facts TicketDesk::facts() @var list<array> $events TicketDesk::events()
 * @var list<array{id:int,title:string,body:string}> $templates @var list<array{id:int,name:string}> $agents
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
                    <div class="erp-rich-text"><?= $ticket['message_html'] ?? \Source\Support\RichText::html((string)($ticket['message'] ?? '')) /* já limpo (ADR 0610) */ ?></div>
                </li>
                <?php foreach ($messages as $message): ?>
                    <li class="erp-ticket-msg <?= !empty($message['internal']) ? 'erp-ticket-msg--internal' : ($message['from_team'] ? 'erp-ticket-msg--team' : 'erp-ticket-msg--requester') ?>">
                        <header><strong><?= $this->e($message['author']) ?></strong><small><?= !empty($message['internal']) ? 'Nota interna' : ($message['from_team'] ? 'Equipe Connect' : 'Solicitante') ?> · <?= $when($message['created_at']) ?></small></header>
                        <div class="erp-rich-text"><?= $message['message_html'] ?? \Source\Support\RichText::html((string)($message['message'] ?? '')) /* já limpo (ADR 0610) */ ?></div>
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

<?php if ($canAttend): ?>
        <form class="erp-card erp-form erp-ticket-composer" id="responder" method="post" action="<?= erp_url($base . '/' . (int)$ticket['id'] . '/atendimento') ?>" enctype="multipart/form-data" novalidate data-erp-ticket-composer>
            <div class="erp-card__head">
                <div class="erp-ticket-modes" role="group" aria-label="Tipo de mensagem">
                    <button type="button" class="erp-btn erp-btn--sm is-active" data-erp-ticket-mode="0" aria-pressed="true">Responder</button>
                    <button type="button" class="erp-btn erp-btn--sm" data-erp-ticket-mode="1" aria-pressed="false">Nota interna</button>
                </div>
                <span class="erp-ticket-timer" title="Tempo dedicado a esta resposta">
                    <?= $icon('clock', 'erp-icon--sm') ?><strong data-erp-ticket-timer>00:00:00</strong>
                    <button type="button" class="erp-btn erp-btn--sm" data-erp-ticket-timer-toggle aria-label="Pausar cronômetro">Pausar</button>
                    <button type="button" class="erp-btn erp-btn--sm" data-erp-ticket-timer-reset aria-label="Zerar cronômetro">Zerar</button>
                </span>
            </div>
            <?= csrf_input() ?>
            <input type="hidden" name="is_internal" value="0" data-erp-ticket-internal>
            <input type="hidden" name="time_spent" value="0" data-erp-ticket-time>
            <?php if ($templates !== []): ?>
                <div class="erp-field">
                    <label for="tk-template">Resposta rápida</label>
                    <select class="erp-select" id="tk-template" data-erp-ticket-template>
                        <option value="">Inserir resposta rápida…</option>
                        <?php foreach ($templates as $template): ?><option value="<?= $this->e($template['body']) ?>"><?= $this->e($template['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="erp-field">
                <label for="tk-reply" data-erp-ticket-label>Resposta para quem pediu</label>
                <textarea class="erp-textarea" data-moves-editor="basic" id="tk-reply" name="message" rows="6" required maxlength="10000" placeholder="Escreva a resposta…"<?= isset($errors['message']) ? ' aria-invalid="true" aria-describedby="tk-reply-error"' : '' ?>><?= $this->e((string)($old['message'] ?? '')) ?></textarea>
                <span class="erp-field__error" id="tk-reply-error"><?= $this->e($errors['message'] ?? $errors['attachments'] ?? '') ?></span>
            </div>
            <div class="erp-field">
                <label for="tk-files">Anexos</label>
                <input class="erp-input" id="tk-files" type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx,.txt">
            </div>
            <div class="erp-form__actions">
                <label class="erp-check"><input type="checkbox" name="resolve_after" value="1"> Resolver após enviar</label>
                <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Enviar</button>
            </div>
        </form>
<?php else: ?>
        <form class="erp-card erp-form" id="responder" method="post" action="<?= erp_url($base . '/' . (int)$ticket['id'] . '/responder') ?>" novalidate>
            <div class="erp-card__head"><div><h2><?= $closed ? 'Reabrir com uma mensagem' : 'Responder' ?></h2><p><?= $closed ? 'A mensagem reabre o chamado e volta para a fila do Help Desk.' : 'A mensagem vai para a equipe do Help Desk, que é avisada na hora.' ?></p></div></div>
            <?= csrf_input() ?>
            <div class="erp-field">
                <label for="tk-reply">Mensagem</label>
                <textarea class="erp-textarea" data-moves-editor="basic" id="tk-reply" name="message" rows="4" required maxlength="10000"<?= isset($errors['message']) ? ' aria-invalid="true" aria-describedby="tk-reply-error"' : '' ?>><?= $this->e((string)($old['message'] ?? '')) ?></textarea>
                <span class="erp-field__error" id="tk-reply-error"><?= $this->e($errors['message'] ?? '') ?></span>
            </div>
            <div class="erp-form__actions">
                <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?><?= $closed ? 'Enviar e reabrir' : 'Enviar mensagem' ?></button>
            </div>
        </form>
<?php endif; ?>
    </div>

    <aside class="erp-stack">
        <?php if ($canAttend): ?>
            <form class="erp-card erp-form" id="triagem" method="post" action="<?= erp_url($base . '/' . (int)$ticket['id'] . '/atender') ?>">
                <div class="erp-card__head"><div><h2>Triagem</h2><p>Propriedades do chamado, como no Help Desk.</p></div></div>
                <?= csrf_input() ?>
                <div class="erp-field"><label for="tg-status">Situação</label>
                    <select class="erp-select" id="tg-status" name="status"><?php foreach (TicketService::STATUS_LABELS as $key => $label): ?><option value="<?= $key ?>"<?= $ticket['status'] === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="erp-field"><label for="tg-priority">Prioridade</label>
                    <select class="erp-select" id="tg-priority" name="priority"><?php foreach (TicketService::PRIORITY_LABELS as $key => $label): ?><option value="<?= $key ?>"<?= $ticket['priority'] === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="erp-field"><label for="tg-assigned">Responsável</label>
                    <select class="erp-select" id="tg-assigned" name="assigned_to"><option value="">Não atribuído</option><?php foreach ($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>"<?= (int)($ticket['assigned_to']['id'] ?? 0) === (int)$agent['id'] ? ' selected' : '' ?>><?= $this->e($agent['name']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field"><label for="tg-category">Categoria</label><input class="erp-input" id="tg-category" name="category" maxlength="100" value="<?= $this->e((string)($ticket['category'] ?? '')) ?>" placeholder="Ex.: Ocorrência"></div>
                <div class="erp-field"><label for="tg-team">Equipe</label><input class="erp-input" id="tg-team" name="team" maxlength="100" value="<?= $this->e((string)($facts['team'] ?? '')) ?>" placeholder="Ex.: Manutenção"></div>
                <div class="erp-field"><label for="tg-tags">Tags</label><input class="erp-input" id="tg-tags" name="tags" maxlength="500" value="<?= $this->e((string)($facts['tags'] ?? '')) ?>" placeholder="vazamento, garagem"></div>
                <div class="erp-form__actions"><button class="erp-btn erp-btn--primary" type="submit">Salvar alterações</button></div>
            </form>
            <form method="post" action="<?= erp_url($base . '/' . (int)$ticket['id'] . '/atender') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="status" value="<?= $closed ? 'open' : 'resolved' ?>">
                <button class="erp-btn <?= $closed ? '' : 'erp-btn--primary' ?> erp-btn--block" type="submit"><?= $icon($closed ? 'edit' : 'check', 'erp-icon--sm') ?><?= $closed ? 'Reabrir chamado' : 'Resolver chamado' ?></button>
            </form>
        <?php endif; ?>
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
                <div><dt>Prazo SLA</dt><dd<?= $late ? ' style="color:var(--erp-danger)"' : '' ?>><?= $closed ? 'Finalizado' : $when($ticket['due_at']) . ($late ? ' · vencido' : '') ?></dd></div>
                <div><dt>Aberto em</dt><dd><?= $when($ticket['created_at']) ?></dd></div>
                <?php if ($ticket['resolved_at'] !== null): ?><div><dt>Resolvido em</dt><dd><?= $when($ticket['resolved_at']) ?></dd></div><?php endif; ?>
                <div><dt>Atualizado</dt><dd><?= $when($facts['updated_at'] ?? $ticket['created_at']) ?></dd></div>
                <div><dt>Primeira resposta</dt><dd><?= $facts['first_response_at'] !== null ? $when($facts['first_response_at']) : 'Pendente' ?></dd></div>
                <div><dt>Respostas</dt><dd><?= (int)$facts['replies'] - ($canAttend ? 0 : (int)$facts['internal_notes']) ?></dd></div>
                <?php if ($canAttend): ?><div><dt>Tempo dedicado</dt><dd><?= sprintf('%02d:%02d:%02d', intdiv($facts['work_seconds'], 3600), intdiv($facts['work_seconds'] % 3600, 60), $facts['work_seconds'] % 60) ?></dd></div><?php endif; ?>
                <?php if (!empty($ticket['category'])): ?><div><dt>Categoria</dt><dd><?= $this->e((string)$ticket['category']) ?></dd></div><?php endif; ?>
            </dl>
        </section>
        <?php if ($canAttend): ?>
            <details class="erp-card erp-ticket-history" open>
                <summary><h2>Histórico de alterações</h2> <span class="erp-badge erp-badge--plain"><?= count($events) ?></span></summary>
                <?php if ($events === []): ?><p class="erp-muted">Nenhuma alteração registrada.</p><?php endif; ?>
                <ol>
                    <?php foreach ($events as $event): ?>
                        <li><strong><?= $this->e($event['label']) ?></strong> <small><?= $this->e($event['author']) ?> · <?= $when($event['created_at']) ?></small>
                            <p><?= $event['old'] !== null && $event['old'] !== '' ? $this->e($event['old']) . ' → ' : '' ?><?= $this->e((string)$event['new']) ?></p></li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>
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
