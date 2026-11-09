<?php
/**
 * Chamados (CN-004, ADR 0034): abertura para o Help Desk e acompanhamento no ERP.
 * @var string $base
 * @var ?array{type:string,text:string,protocol:?string} $flash
 * @var array<string,string> $errors @var array<string,mixed> $old
 * @var list<array{id:int,name:string}> $condominiums
 * @var array<int, list<array{id:int,label:string}>> $unitsByCondominium
 * @var list<array> $tickets TicketService::present()
 * @var array{condominium_id:int,status:string,q:string} $filters
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
    <?php if ($helpdeskUrl !== null): ?><a class="erp-btn" href="<?= $this->e($helpdeskUrl) ?>"><?= $icon('help', 'erp-icon--sm') ?>Fila do Help Desk</a><?php endif; ?>
    <a class="erp-btn erp-btn--primary" href="#abrir"><?= $icon('plus', 'erp-icon--sm') ?>Abrir chamado</a>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Operacional',
    'pageTitle' => 'Chamados',
    'pageLead' => 'Abra chamados para o Help Desk com o condomínio, a unidade e quem pediu, e acompanhe o andamento.',
    'pageActions' => $actions,
]);
?>
<?php if (!empty($flash)): ?>
    <div class="erp-alert erp-alert--<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $this->e($flash['text']) ?><?php if (!empty($flash['ticket_id'])): ?> <a href="<?= erp_url($base . '/' . (int)$flash['ticket_id']) ?>">Acompanhar o chamado</a><?php endif; ?></div>
<?php endif; ?>

<form class="erp-card erp-form" id="abrir" method="post" action="<?= erp_url($base) ?>" novalidate>
    <div class="erp-card__head"><div><h2>Abrir chamado</h2><p>O chamado cai na fila do Help Desk e a equipe é avisada na hora.</p></div></div>
    <?= csrf_input() ?>
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
        </div>
        <div class="erp-field erp-col-12">
            <label for="tk-message">Descrição <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <textarea class="erp-textarea" id="tk-message" name="message" rows="5" required<?= $invalid('message', 'tk-message') ?>><?= $this->e($value('message')) ?></textarea>
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
    <div class="erp-form__actions">
        <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Enviar para o Help Desk</button>
    </div>
</form>

<section class="erp-card erp-card--flush" aria-labelledby="tk-list-title">
    <div class="erp-card__head">
        <div><h2 id="tk-list-title">Chamados</h2><p>Dos seus condomínios e os que você abriu, dos mais recentes para os mais antigos.</p></div>
        <form class="erp-filters" method="get" action="<?= erp_url($base) ?>">
            <label class="erp-sr-only" for="tk-f-q">Buscar</label>
            <input class="erp-input" id="tk-f-q" name="q" value="<?= $this->e($filters['q']) ?>" placeholder="Protocolo ou assunto">
            <label class="erp-sr-only" for="tk-f-condo">Condomínio</label>
            <select class="erp-select" id="tk-f-condo" name="condominio">
                <option value="">Todos os condomínios</option>
                <?php foreach ($condominiums as $condominium): ?>
                    <option value="<?= (int)$condominium['id'] ?>"<?= $filters['condominium_id'] === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="erp-sr-only" for="tk-f-status">Situação</label>
            <select class="erp-select" id="tk-f-status" name="situacao">
                <option value="">Todas as situações</option>
                <?php foreach (TicketService::STATUS_LABELS as $key => $label): ?>
                    <option value="<?= $key ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button class="erp-btn" type="submit"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button>
        </form>
    </div>
    <?php if ($tickets === []): ?>
        <div style="padding:0 var(--erp-space-5) var(--erp-space-5)">
            <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'help', 'emptyTitle' => 'Nenhum chamado encontrado', 'emptyText' => 'Os chamados abertos aqui, no Help Desk, no site ou pela API aparecem nesta lista.']); ?>
        </div>
    <?php else: ?>
        <div class="erp-table-wrap">
            <table class="erp-table">
                <thead><tr><th>Protocolo</th><th>Assunto</th><th>Condomínio / unidade</th><th>Solicitante</th><th>Origem</th><th>Prioridade</th><th>Situação</th><th>Aberto em</th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><a class="erp-ticket-link" href="<?= erp_url($base . '/' . (int)$ticket['id']) ?>"><?= $this->e($ticket['protocol']) ?></a></td>
                        <td><a href="<?= erp_url($base . '/' . (int)$ticket['id']) ?>"><?= $this->e($ticket['subject']) ?></a></td>
                        <td><?= $this->e($ticket['condominium']['name'] ?? '—') ?><?php if ($ticket['unit'] !== null): ?><br><small><?= $this->e('Unid. ' . $ticket['unit']['label']) ?></small><?php endif; ?></td>
                        <td><?= $this->e($ticket['requester']['name'] ?? '—') ?></td>
                        <td><?= $this->e(TicketService::ORIGIN_LABELS[$ticket['origin']] ?? $ticket['origin']) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $priorityTone[$ticket['priority']] ?? 'plain' ?>"><?= $this->e(TicketService::PRIORITY_LABELS[$ticket['priority']] ?? $ticket['priority']) ?></span></td>
                        <td><span class="erp-badge erp-badge--<?= $statusTone[$ticket['status']] ?? 'plain' ?>"><?= $this->e(TicketService::STATUS_LABELS[$ticket['status']] ?? $ticket['status']) ?></span></td>
                        <td><?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
