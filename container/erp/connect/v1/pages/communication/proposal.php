<?php
/**
 * Uma proposta do site no ERP (CN-011, ADR 0644): dados do pedido, acompanhamento (situação e responsável) e as
 * respostas em PDF já geradas no Studio. Gerar e enviar o PDF continua no Studio (botão "Responder no Studio").
 * @var array $currentPage @var array $proposal ProposalDesk::present() @var list<array{id:int,name:string}> $proposalTeam
 * @var list<array{id:int,subject:string,status:string,created_at:string}> $proposalResponses @var string $studioUrl
 */
use Source\Services\Proposals\ProposalDesk;

$this->layout('default');
$route = erp_url($currentPage['route']);
$when = static fn (?string $date): string => $date ? date('d/m/Y H:i', (int)strtotime($date)) : '—';
$tone = ProposalDesk::STATUSES[$proposal['status']][1] ?? 'plain';
$phone = preg_replace('/\D+/', '', $proposal['whatsapp']);
$responseLabels = ['generated' => 'PDF gerado', 'queued' => 'Na fila', 'sent' => 'Enviada', 'failed' => 'Falhou', 'draft' => 'Rascunho'];
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($route) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar às propostas</a>
    <a class="erp-btn erp-btn--primary" href="<?= $this->e($studioUrl) ?>"><?= $icon('file', 'erp-icon--sm') ?>Responder no Studio</a>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Propostas do site · ' . $proposal['protocol'],
    'pageTitle' => $proposal['condominium'],
    'pageLead' => 'Pedido recebido em ' . $when($proposal['created_at']) . '.',
    'pageActions' => $actions,
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>

<div class="erp-grid erp-grid--main">
    <section class="erp-card" aria-labelledby="prop-requester">
        <div class="erp-card__head"><div><h2 id="prop-requester"><?= $this->e($proposal['name']) ?></h2><p><?= $this->e($proposal['profile_label']) ?></p></div><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($proposal['status_label']) ?></span></div>
        <dl class="erp-detail-grid">
            <div><dt>E-mail</dt><dd><a href="mailto:<?= $this->e($proposal['email']) ?>"><?= $this->e($proposal['email']) ?></a></dd></div>
            <div><dt>WhatsApp</dt><dd><?php if ($phone !== ''): ?><a href="https://wa.me/55<?= $this->e($phone) ?>" target="_blank" rel="noopener"><?= $this->e($proposal['whatsapp']) ?></a><?php else: ?>—<?php endif; ?></dd></div>
            <div><dt>Condomínio</dt><dd><?= $this->e($proposal['condominium']) ?></dd></div>
            <div><dt>Unidades</dt><dd><?= (int)$proposal['units'] ?></dd></div>
            <div><dt>Protocolo</dt><dd><?= $this->e($proposal['protocol']) ?></dd></div>
            <div><dt>Primeiro contato</dt><dd><?= $this->e($when($proposal['contacted_at'])) ?></dd></div>
            <div><dt>Aceita novidades</dt><dd><?= $proposal['marketing_consent'] ? 'Sim' : 'Não (não mande divulgação)' ?></dd></div>
        </dl>
        <?php if ($proposal['message'] !== null && $proposal['message'] !== ''): ?>
            <h3 class="erp-proposal-message__title">Necessidade informada</h3>
            <p class="erp-proposal-message"><?= nl2br($this->e($proposal['message'])) ?></p>
        <?php endif; ?>
    </section>

    <aside class="erp-card" aria-labelledby="prop-follow">
        <div class="erp-card__head"><div><h2 id="prop-follow">Acompanhamento</h2><p>Situação no funil e quem cuida do pedido.</p></div></div>
        <form class="erp-form" method="post" action="<?= $this->e($route . '/' . (int)$proposal['id']) ?>" data-erp-loading>
            <?= csrf_input() ?>
            <div class="erp-form__grid">
                <div class="erp-field erp-col-12">
                    <label for="prop-status">Situação</label>
                    <select class="erp-select" id="prop-status" name="status" required>
                        <?php foreach (ProposalDesk::STATUSES as $key => [$label]): ?><option value="<?= $this->e($key) ?>"<?= $proposal['status'] === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="erp-field erp-col-12">
                    <label for="prop-assigned">Responsável</label>
                    <select class="erp-select" id="prop-assigned" name="assigned_to">
                        <option value="">Sem responsável</option>
                        <?php foreach ($proposalTeam as $member): ?><option value="<?= (int)$member['id'] ?>"<?= $proposal['assigned_to'] === (int)$member['id'] ? ' selected' : '' ?>><?= $this->e($member['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar acompanhamento</button></div>
        </form>
    </aside>
</div>

<section class="erp-card" aria-labelledby="prop-responses">
    <div class="erp-card__head"><div><h2 id="prop-responses">Respostas em PDF</h2><p>Geradas e enviadas pelo Studio. Para fazer uma nova, use "Responder no Studio".</p></div><span class="erp-badge erp-badge--plain"><?= count($proposalResponses) ?></span></div>
    <?php if ($proposalResponses === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhuma resposta gerada</strong><p>Quando a proposta em PDF for gerada no Studio, ela aparece aqui.</p></div>
    <?php else: ?>
        <ul class="erp-proposal-responses">
            <?php foreach ($proposalResponses as $response): ?>
                <li><strong><?= $this->e($response['subject']) ?></strong><span class="erp-muted"><?= $this->e($when($response['created_at'])) ?></span><span class="erp-badge erp-badge--<?= in_array($response['status'], ['sent', 'queued'], true) ? 'success' : ($response['status'] === 'failed' ? 'danger' : 'plain') ?>"><?= $this->e($responseLabels[$response['status']] ?? $response['status']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
