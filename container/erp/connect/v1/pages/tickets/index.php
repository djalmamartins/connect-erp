<?php
/**
 * Chamados (CN-004, ADR 0034): abertura para o Help Desk e acompanhamento no ERP.
 * Organização da tela (ADR 0622): a mesma fila do Help Desk (contadores, barra de filtros com a ação
 * em lote ao lado e as mesmas colunas), no visual do ERP; o formulário de abertura fica num modal.
 * @var string $base
 * @var ?array{type:string,text:string,protocol:?string} $flash
 * @var array<string,string> $errors @var array<string,mixed> $old
 * @var list<array{id:int,name:string}> $condominiums
 * @var array<int, list<array{id:int,label:string}>> $unitsByCondominium
 * @var list<array> $tickets TicketService::present()
 * @var array{condominium_id:int,status:string,q:string,kind:string,priority:string,assigned:string,due:string,origin:string} $filters
 * @var array $metrics TicketDesk::metrics() @var bool $canAttend @var list<array{id:int,name:string}> $agents
 * @var ?string $helpdeskUrl link para a fila do Help Desk (quem atende)
 */
use Source\Services\Support\TicketService;

$this->layout('default');
$error = static fn (string $key): string => $errors[$key] ?? '';
$value = static fn (string $key): string => (string)($old[$key] ?? '');
$invalid = static fn (string $key, string $id): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$statusTone = ['open' => 'info', 'in_progress' => 'warning', 'waiting_customer' => 'warning', 'resolved' => 'success', 'closed' => 'plain'];
$priorityTone = ['low' => 'plain', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
$requesterOther = ($old['requester'] ?? 'me') === 'other';
$selectedCondo = (int)($old['condominium_id'] ?? 0);
$selectedUnit = (int)($old['unit_id'] ?? 0);
ob_start(); ?>
    <?php if ($helpdeskUrl !== null): ?><a class="erp-btn" href="<?= $this->e($helpdeskUrl) ?>"><?= $icon('help', 'erp-icon--sm') ?>Fila do SAC</a><?php endif; ?>
    <button class="erp-btn erp-btn--primary" type="button" data-erp-modal-open="tk-new"><?= $icon('plus', 'erp-icon--sm') ?>Abrir chamado</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Atendimento',
    'pageTitle' => 'Chamados',
    'pageLead' => 'A fila do SAC dos seus condomínios: priorize, distribua e acompanhe cada atendimento.',
    'pageActions' => $actions,
]);
?>
<?php if (!empty($flash)): ?>
    <div class="erp-alert erp-alert--<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $this->e($flash['text']) ?><?php if (!empty($flash['ticket_id'])): ?> <a href="<?= erp_url($base . '/' . (int)$flash['ticket_id']) ?>">Acompanhar o chamado</a><?php endif; ?></div>
<?php endif; ?>



<?php
// Fila como no Help Desk (ADR 0307): contadores por situação e de prazo, filtros, SLA e situação em lote.
$query = static function (array $change) use ($filters): string {
    $map = ['q' => 'q', 'condominium_id' => 'condominio', 'status' => 'situacao', 'kind' => 'tipo', 'priority' => 'prioridade', 'assigned' => 'responsavel', 'due' => 'prazo', 'origin' => 'origem'];
    $kind = ['occurrence' => 'ocorrencias', 'other' => 'outros'];
    $params = [];
    foreach (array_merge($filters, $change) as $key => $value) {
        $value = $key === 'kind' ? ($kind[$value] ?? '') : (string)$value;
        if ($value !== '' && $value !== '0' && isset($map[$key])) {
            $params[$map[$key]] = $value;
        }
    }
    return $params === [] ? '' : '?' . http_build_query($params);
};
$slaLabel = static function (array $ticket): array {
    $state = \Source\Services\Support\TicketDesk::slaState($ticket['status'], $ticket['due_at']);
    return match ($state) { 'done' => ['Finalizado', 'plain'], 'late' => ['Vencido', 'danger'], default => [date('d/m H:i', strtotime($ticket['due_at'])), 'info'] };
};
?>
<nav class="erp-ticket-counters" id="fila" aria-label="Resumo da fila">
    <a class="erp-ticket-counter<?= $filters['status'] === '' && $filters['due'] === '' ? ' is-active' : '' ?>" href="<?= erp_url($base . $query(['status' => '', 'due' => ''])) ?>"><span>Todos</span><strong><?= (int)$metrics['total'] ?></strong></a>
    <?php foreach (TicketService::STATUS_LABELS as $key => $label): ?>
        <a class="erp-ticket-counter erp-ticket-counter--<?= $key ?><?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= erp_url($base . $query(['status' => $key, 'due' => ''])) ?>"><span><?= $label ?></span><strong><?= (int)$metrics['by_status'][$key] ?></strong></a>
    <?php endforeach; ?>
    <a class="erp-ticket-counter erp-ticket-counter--late<?= $filters['due'] === 'overdue' ? ' is-active' : '' ?>" href="<?= erp_url($base . $query(['due' => 'overdue', 'status' => ''])) ?>"><span>SLA vencido</span><strong><?= (int)$metrics['overdue'] ?></strong></a>
    <a class="erp-ticket-counter<?= $filters['due'] === 'today' ? ' is-active' : '' ?>" href="<?= erp_url($base . $query(['due' => 'today', 'status' => ''])) ?>"><span>Vence hoje</span><strong><?= (int)$metrics['due_today'] ?></strong></a>
    <a class="erp-ticket-counter<?= $filters['assigned'] === 'unassigned' ? ' is-active' : '' ?>" href="<?= erp_url($base . $query(['assigned' => 'unassigned'])) ?>"><span>Sem responsável</span><strong><?= (int)$metrics['unassigned'] ?></strong></a>
</nav>

<section class="erp-card erp-card--flush erp-ticket-queue" aria-label="Fila de chamados">
    <header class="erp-ticket-toolbar">
    <form class="erp-filters erp-ticket-filters" method="get" action="<?= erp_url($base) ?>">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="situacao" value="<?= $this->e($filters['status']) ?>"><?php endif; ?>
        <label class="erp-ticket-search"><span class="erp-sr-only">Buscar</span><?= $icon('search', 'erp-icon--sm') ?><input class="erp-input" id="tk-f-q" name="q" value="<?= $this->e($filters['q']) ?>" placeholder="Protocolo ou assunto"></label>
        <label class="erp-sr-only" for="tk-f-condo">Condomínio</label>
        <select class="erp-select" id="tk-f-condo" name="condominio">
            <option value="">Todos os condomínios</option>
            <?php foreach ($condominiums as $condominium): ?>
                <option value="<?= (int)$condominium['id'] ?>"<?= $filters['condominium_id'] === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="erp-sr-only" for="tk-f-priority">Prioridade</label>
        <select class="erp-select" id="tk-f-priority" name="prioridade">
            <option value="">Todas as prioridades</option>
            <?php foreach (TicketService::PRIORITY_LABELS as $key => $label): ?>
                <option value="<?= $key ?>"<?= $filters['priority'] === $key ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <label class="erp-sr-only" for="tk-f-assigned">Responsável</label>
        <select class="erp-select" id="tk-f-assigned" name="responsavel">
            <option value="">Todos os responsáveis</option>
            <option value="unassigned"<?= $filters['assigned'] === 'unassigned' ? ' selected' : '' ?>>Sem responsável</option>
            <?php foreach ($agents as $agent): ?>
                <option value="<?= (int)$agent['id'] ?>"<?= $filters['assigned'] === (string)$agent['id'] ? ' selected' : '' ?>><?= $this->e($agent['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="erp-sr-only" for="tk-f-due">Prazo</label>
        <select class="erp-select" id="tk-f-due" name="prazo">
            <option value="">Qualquer prazo</option>
            <option value="today"<?= $filters['due'] === 'today' ? ' selected' : '' ?>>Vence hoje</option>
            <option value="overdue"<?= $filters['due'] === 'overdue' ? ' selected' : '' ?>>SLA vencido</option>
        </select>
        <label class="erp-sr-only" for="tk-f-origin">Origem</label>
        <select class="erp-select" id="tk-f-origin" name="origem">
            <option value="">Todas as origens</option>
            <?php foreach (TicketService::ORIGIN_LABELS as $key => $label): ?>
                <option value="<?= $key ?>"<?= $filters['origin'] === $key ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <label class="erp-sr-only" for="tk-f-kind">Tipo</label>
        <select class="erp-select" id="tk-f-kind" name="tipo">
            <option value="">Chamados e ocorrências</option>
            <option value="ocorrencias"<?= ($filters['kind'] ?? '') === 'occurrence' ? ' selected' : '' ?>>Só ocorrências</option>
            <option value="outros"<?= ($filters['kind'] ?? '') === 'other' ? ' selected' : '' ?>>Sem as ocorrências</option>
        </select>
        <button class="erp-btn" type="submit"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button>
        <?php if ($canAttend && $tickets !== []): /* lote na mesma barra, como no Help Desk; os campos usam form="tk-bulk" */ ?>
            <span class="erp-ticket-bulk">
                <label class="erp-sr-only" for="tk-bulk-status">Situação dos selecionados</label>
                <select class="erp-select" id="tk-bulk-status" name="status" form="tk-bulk" required>
                    <option value="">Com os selecionados…</option>
                    <?php foreach (TicketService::STATUS_LABELS as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?>
                </select>
                <button class="erp-btn" type="submit" form="tk-bulk">Aplicar</button>
            </span>
        <?php endif; ?>
    </form>
    <?php if ($canAttend && $tickets !== []): ?>
        <form id="tk-bulk" method="post" action="<?= erp_url($base . '/lote') ?>" hidden><?= csrf_input() ?></form>
    <?php endif; ?>
    </header>
    <?php if ($tickets === []): ?>
        <div style="padding:0 var(--erp-space-5) var(--erp-space-5)">
            <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'help', 'emptyTitle' => 'Nenhum chamado encontrado', 'emptyText' => 'Os chamados abertos aqui, no SAC, no site ou pela API aparecem nesta lista.']); ?>
        </div>
    <?php else: ?>
        <div class="erp-table-wrap">
            <table class="erp-table erp-ticket-table">
                <thead><tr><?php if ($canAttend): ?><th><input type="checkbox" data-erp-ticket-all aria-label="Selecionar todos"></th><?php endif; ?><th>Protocolo</th><th>Atualizado</th><th>Solicitante</th><th>Assunto</th><th>Situação</th><th>Responsável</th><th>Prioridade</th><th>SLA</th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $ticket): [$sla, $slaTone] = $slaLabel($ticket); ?>
                    <tr<?= $slaTone === 'danger' ? ' class="is-overdue"' : '' ?>>
                        <?php if ($canAttend): ?><td><input type="checkbox" name="ticket_ids[]" value="<?= (int)$ticket['id'] ?>" form="tk-bulk" aria-label="Selecionar <?= $this->e($ticket['protocol']) ?>"></td><?php endif; ?>
                        <td><a class="erp-ticket-link" href="<?= erp_url($base . '/' . (int)$ticket['id']) ?>"><?= $this->e($ticket['protocol']) ?></a></td>
                        <td><?= date('d/m/y H:i', strtotime($ticket['updated_at'])) ?></td>
                        <td class="erp-ticket-cell"><strong><?= $this->e($ticket['requester']['name'] ?? '—') ?></strong><small><?= $this->e(implode(' · ', array_filter([$ticket['condominium']['name'] ?? null, $ticket['unit'] !== null ? 'Unid. ' . $ticket['unit']['label'] : null, TicketService::ORIGIN_LABELS[$ticket['origin']] ?? $ticket['origin']]))) ?></small></td>
                        <td class="erp-ticket-cell erp-ticket-cell--subject"><a href="<?= erp_url($base . '/' . (int)$ticket['id']) ?>"><?= $this->e($ticket['subject']) ?></a><?php if (($ticket['occurrence_id'] ?? null) !== null): ?><a class="erp-badge erp-badge--plain" href="<?= erp_url('/operacional/ocorrencias/' . (int)$ticket['occurrence_id']) ?>"><?= $this->e('Ocorrência #' . (int)$ticket['occurrence_id']) ?></a><?php endif; ?></td>
                        <td><span class="erp-badge erp-badge--<?= $statusTone[$ticket['status']] ?? 'plain' ?>"><?= $this->e(TicketService::STATUS_LABELS[$ticket['status']] ?? $ticket['status']) ?></span></td>
                        <td><?= $this->e($ticket['assigned_to']['name'] ?? 'Não atribuído') ?></td>
                        <td><span class="erp-badge erp-badge--<?= $priorityTone[$ticket['priority']] ?? 'plain' ?>"><?= $this->e(TicketService::PRIORITY_LABELS[$ticket['priority']] ?? $ticket['priority']) ?></span></td>
                        <td><span class="erp-badge erp-badge--<?= $slaTone ?>"><?= $this->e($sla) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog class="erp-modal erp-modal--wide" id="tk-new" aria-labelledby="tk-new-title"<?= $errors !== [] ? ' data-erp-modal-auto' : '' ?>>
<form class="erp-form" id="abrir" method="post" action="<?= erp_url($base) ?>" novalidate>
    <div class="erp-modal__head">
        <div><h2 id="tk-new-title">Abrir chamado</h2><p class="erp-muted">O chamado cai na fila do SAC e a equipe é avisada na hora.</p></div>
        <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
    </div>
    <?= csrf_input() ?>
    <div class="erp-modal__body">
    <div class="erp-form__grid">
        <div class="erp-field erp-col-6">
            <label for="tk-condo">Condomínio</label>
            <select class="erp-select" id="tk-condo" name="condominium_id"<?= $invalid('condominium_id', 'tk-condo') ?>>
                <option value="">Sem condomínio (assunto geral)</option>
                <?php foreach ($condominiums as $condominium): ?>
                    <option value="<?= (int)$condominium['id'] ?>"<?= $selectedCondo === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="erp-field__error" id="tk-condo-error"><?= $this->e($error('condominium_id')) ?></span>
        </div>
        <div class="erp-field erp-col-6">
            <label for="tk-unit">Unidade</label>
            <select class="erp-select" id="tk-unit" name="unit_id"<?= $invalid('unit_id', 'tk-unit') ?>>
                <option value="">Sem unidade</option>
                <?php foreach ($condominiums as $condominium):
                    $units = $unitsByCondominium[(int)$condominium['id']] ?? [];
                    if ($units === []) { continue; } ?>
                    <optgroup label="<?= $this->e($condominium['name']) ?>">
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= (int)$unit['id'] ?>"<?= $selectedUnit === (int)$unit['id'] ? ' selected' : '' ?>><?= $this->e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <span class="erp-field__hint">A unidade precisa ser do condomínio escolhido.</span>
            <span class="erp-field__error" id="tk-unit-error"><?= $this->e($error('unit_id')) ?></span>
        </div>
        <div class="erp-field erp-col-12">
            <label for="tk-subject">Assunto <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <input class="erp-input" id="tk-subject" name="subject" maxlength="180" required value="<?= $this->e($value('subject')) ?>"<?= $invalid('subject', 'tk-subject') ?>>
            <span class="erp-field__error" id="tk-subject-error"><?= $this->e($error('subject')) ?></span>
            <?php /* Central de Ajuda (ADR 0153): artigos sugeridos pelo assunto, antes de abrir o chamado. */ ?>
            <div class="erp-help-suggest" data-erp-help-suggest data-api="<?= $this->e(url('/api/v1/help/articles')) ?>" data-for="tk-subject" aria-live="polite" hidden></div>
        </div>
        <div class="erp-field erp-col-12">
            <label for="tk-message">Descrição <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <textarea class="erp-textarea" data-moves-editor="basic" id="tk-message" name="message" rows="5" required<?= $invalid('message', 'tk-message') ?>><?= $this->e($value('message')) ?></textarea>
            <span class="erp-field__error" id="tk-message-error"><?= $this->e($error('message')) ?></span>
        </div>
        <div class="erp-field erp-col-6">
            <label for="tk-area">Área</label>
            <select class="erp-select" id="tk-area" name="area">
                <?php foreach (TicketService::AREA_LABELS as $key => $label): ?>
                    <option value="<?= $key ?>"<?= ($value('area') ?: 'general') === $key ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="erp-field erp-col-6">
            <label for="tk-priority">Prioridade</label>
            <select class="erp-select" id="tk-priority" name="priority">
                <?php foreach (TicketService::PRIORITY_LABELS as $key => $label): ?>
                    <option value="<?= $key ?>"<?= ($value('priority') ?: 'medium') === $key ? ' selected' : '' ?>><?= $label ?> · até <?= TicketService::slaHours($key) ?> h</option>
                <?php endforeach; ?>
            </select>
        </div>
        <fieldset class="erp-field erp-col-12">
            <legend>Quem pediu</legend>
            <label><input type="radio" name="requester" value="me"<?= $requesterOther ? '' : ' checked' ?>> Eu mesmo (<?= $this->e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?>)</label>
            <label><input type="radio" name="requester" value="other"<?= $requesterOther ? ' checked' : '' ?>> Um morador ou outra pessoa</label>
        </fieldset>
        <div class="erp-field erp-col-4">
            <label for="tk-rname">Nome do solicitante</label>
            <input class="erp-input" id="tk-rname" name="requester_name" maxlength="160" value="<?= $this->e($value('requester_name')) ?>"<?= $invalid('requester_name', 'tk-rname') ?>>
            <span class="erp-field__error" id="tk-rname-error"><?= $this->e($error('requester_name')) ?></span>
        </div>
        <div class="erp-field erp-col-4">
            <label for="tk-remail">E-mail do solicitante</label>
            <input class="erp-input" id="tk-remail" name="requester_email" type="email" maxlength="190" value="<?= $this->e($value('requester_email')) ?>"<?= $invalid('requester_email', 'tk-remail') ?>>
            <span class="erp-field__error" id="tk-remail-error"><?= $this->e($error('requester_email')) ?></span>
        </div>
        <div class="erp-field erp-col-4">
            <label for="tk-rphone">Telefone</label>
            <input class="erp-input" id="tk-rphone" name="requester_phone" maxlength="30" inputmode="tel" value="<?= $this->e($value('requester_phone')) ?>">
            <span class="erp-field__hint">Só quando o pedido é de outra pessoa.</span>
        </div>
    </div>
    </div>
    <div class="erp-modal__foot">
        <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
        <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Enviar para o SAC</button>
    </div>
</form>
</dialog>
