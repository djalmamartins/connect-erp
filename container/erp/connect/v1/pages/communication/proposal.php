<?php
/**
 * Uma proposta no ERP (CN-011, ADR 0644 e 0650): dados do pedido, acompanhamento (situação e responsável), gerar o PDF,
 * gerar e enviar por e-mail e as versões já geradas (também as antigas, feitas no Studio).
 * @var array $currentPage @var array $proposal ProposalDesk::present() @var list<array{id:int,name:string}> $proposalTeam
 * @var list<array{id:int,subject:string,template_type:string,status:string,has_pdf:bool,queued_at:?string,created_at:string}> $proposalResponses
 * @var array<string,string> $proposalReply textos do formulário @var array<string,string> $proposalErrors
 */
use Source\Services\Proposals\ProposalComposer;
use Source\Services\Proposals\ProposalDesk;

$this->layout('default');
$route = erp_url($currentPage['route']);
$when = static fn (?string $date): string => $date ? date('d/m/Y H:i', (int)strtotime($date)) : '—';
$tone = ProposalDesk::STATUSES[$proposal['status']][1] ?? 'plain';
$phone = preg_replace('/\D+/', '', $proposal['whatsapp']);
$responseLabels = ['generated' => 'PDF gerado', 'queued' => 'Enviada (na fila)', 'sent' => 'Enviada', 'failed' => 'Falhou', 'draft' => 'Rascunho'];
$base = $route . '/' . (int)$proposal['id'];
$reply = $proposalReply;
$error = function (string $key) use ($proposalErrors): string {
    return isset($proposalErrors[$key]) ? '<span class="erp-field__error" id="pr-' . $key . '-error">' . $this->e($proposalErrors[$key]) . '</span>' : '';
};
$invalid = static fn (string $key): string => isset($proposalErrors[$key]) ? ' aria-invalid="true" aria-describedby="pr-' . $key . '-error"' : '';
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($route) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar às propostas</a>
    <a class="erp-btn erp-btn--primary" href="#prop-reply"><?= $icon('file', 'erp-icon--sm') ?>Preparar proposta</a>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Propostas · ' . $proposal['protocol'],
    'pageTitle' => $proposal['condominium'],
    'pageLead' => ($proposal['origin'] === 'site' ? 'Pedido recebido pelo site em ' : 'Cadastrada no ERP em ') . $when($proposal['created_at']) . '.',
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
            <div><dt>Origem</dt><dd><?= $this->e($proposal['origin_label']) ?></dd></div>
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

<section class="erp-card" id="prop-reply" aria-labelledby="prop-reply-title">
    <div class="erp-card__head"><div><h2 id="prop-reply-title">Gerar proposta em PDF</h2><p>Revise os textos, gere o PDF para conferir e depois envie para <?= $this->e($proposal['email']) ?>. Cada geração vira uma versão.</p></div></div>
    <?php if (isset($proposalErrors['form'])): ?>
        <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($proposalErrors['form']) ?></div></div>
    <?php elseif ($proposalErrors !== []): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><?= $this->e(implode(' ', $proposalErrors)) ?></div></div>
    <?php endif; ?>
    <p class="erp-field__hint">Modelo:
        <?php foreach (ProposalComposer::TEMPLATES as $key => $label): ?>
            <?php if ($reply['template_type'] === $key): ?><strong><?= $this->e($label) ?></strong><?php else: ?><a href="<?= $this->e($base . '?modelo=' . $key . '#prop-reply') ?>"><?= $this->e($label) ?></a><?php endif; ?>
        <?php endforeach; ?>
        (trocar o modelo recarrega os textos padrão)
    </p>
    <form class="erp-form" method="post" action="<?= $this->e($base . '/resposta') ?>" data-erp-confirm="Gerar e enviar esta proposta para <?= $this->e($proposal['email']) ?>? O PDF é gerado e vai por e-mail agora." data-confirm-undo="nao" data-confirm-undo-text="Depois de enviado, o e-mail não volta." data-confirm-action="Gerar e enviar" data-confirm-tone="primary">
        <?= csrf_input() ?>
        <input type="hidden" name="template_type" value="<?= $this->e($reply['template_type']) ?>">
        <div class="erp-form__grid">
            <div class="erp-field erp-col-8"><label for="pr-subject">Assunto</label><input class="erp-input" id="pr-subject" name="subject" maxlength="255" required value="<?= $this->e($reply['subject']) ?>"<?= $invalid('subject') ?>><?= $error('subject') ?></div>
            <div class="erp-field erp-col-4"><label for="pr-valid">Validade</label><input class="erp-input" type="date" id="pr-valid" name="valid_until" min="<?= date('Y-m-d') ?>" required value="<?= $this->e($reply['valid_until']) ?>"<?= $invalid('valid_until') ?>><?= $error('valid_until') ?></div>
            <div class="erp-field erp-col-12"><label for="pr-intro">Apresentação</label><textarea class="erp-textarea" id="pr-intro" name="introduction" rows="5" maxlength="10000" required<?= $invalid('introduction') ?>><?= $this->e($reply['introduction']) ?></textarea><?= $error('introduction') ?></div>
            <div class="erp-field erp-col-12"><label for="pr-scope">Escopo</label><textarea class="erp-textarea" id="pr-scope" name="scope" rows="7" maxlength="10000" required<?= $invalid('scope') ?>><?= $this->e($reply['scope']) ?></textarea><span class="erp-field__hint">Um item por linha.</span><?= $error('scope') ?></div>
            <div class="erp-field erp-col-6"><label for="pr-commercial">Condições comerciais</label><textarea class="erp-textarea" id="pr-commercial" name="commercial_terms" rows="5" maxlength="10000" required placeholder="Ex.: honorários mensais, implantação e itens inclusos"<?= $invalid('commercial_terms') ?>><?= $this->e($reply['commercial_terms']) ?></textarea><?= $error('commercial_terms') ?></div>
            <div class="erp-field erp-col-6"><label for="pr-payment">Forma de pagamento</label><textarea class="erp-textarea" id="pr-payment" name="payment_terms" rows="5" maxlength="500" placeholder="Ex.: vencimento todo dia 10"><?= $this->e($reply['payment_terms']) ?></textarea></div>
            <div class="erp-field erp-col-12"><label for="pr-notes">Observações</label><textarea class="erp-textarea" id="pr-notes" name="notes" rows="3" maxlength="5000" placeholder="Informações complementares, condições especiais ou próximos passos"><?= $this->e($reply['notes']) ?></textarea></div>
        </div>
        <div class="erp-form__actions">
            <button type="submit" class="erp-btn" name="action" value="generate" data-erp-no-confirm><?= $icon('eye', 'erp-icon--sm') ?>Gerar PDF para conferir</button>
            <button type="submit" class="erp-btn erp-btn--primary" name="action" value="send"><?= $icon('check', 'erp-icon--sm') ?>Gerar e enviar por e-mail</button>
        </div>
    </form>
</section>

<section class="erp-card" id="prop-responses" aria-labelledby="prop-responses-title">
    <div class="erp-card__head"><div><h2 id="prop-responses-title">Versões da proposta</h2><p>Cada PDF gerado, do mais novo para o mais antigo. As versões antigas feitas no Studio também aparecem aqui.</p></div><span class="erp-badge erp-badge--plain"><?= count($proposalResponses) ?></span></div>
    <?php if ($proposalResponses === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhuma versão gerada</strong><p>Use "Gerar PDF para conferir" acima. A versão aparece aqui.</p></div>
    <?php else: ?>
        <ul class="erp-proposal-responses">
            <?php foreach ($proposalResponses as $response): ?>
                <li><strong><?= $this->e($response['subject']) ?></strong><span class="erp-muted"><?= $this->e((ProposalComposer::TEMPLATES[$response['template_type']] ?? '') . ' · ' . $when($response['created_at'])) ?></span><span class="erp-badge erp-badge--<?= in_array($response['status'], ['sent', 'queued'], true) ? 'success' : ($response['status'] === 'failed' ? 'danger' : 'plain') ?>"><?= $this->e($responseLabels[$response['status']] ?? $response['status']) ?></span><?php if ($response['has_pdf']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($base . '/pdf/' . $response['id']) ?>" target="_blank" rel="noopener"><?= $icon('eye', 'erp-icon--sm') ?>Abrir PDF</a><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
