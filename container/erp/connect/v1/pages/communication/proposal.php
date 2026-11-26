<?php
/**
 * Uma proposta no ERP (CN-011, ADR 0644 e 0650): dados do pedido, acompanhamento (situação e responsável), anexar os
 * PDFs prontos (até dois: administradora e síndico profissional), enviar por e-mail e as versões (também as do Studio).
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
$responseLabels = ['generated' => 'Anexado', 'queued' => 'Enviada (na fila)', 'sent' => 'Enviada', 'failed' => 'Falhou', 'draft' => 'Rascunho'];
$base = $route . '/' . (int)$proposal['id'];
$quote = $proposal['quote'];
$money = static fn (?int $cents): string => $cents === null ? '' : number_format($cents / 100, 2, ',', '.');
$brl = static fn (?int $cents): string => $cents === null ? '—' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$reply = $proposalReply;
$error = function (string $key) use ($proposalErrors): string {
    return isset($proposalErrors[$key]) ? '<span class="erp-field__error" id="pr-' . $key . '-error">' . $this->e($proposalErrors[$key]) . '</span>' : '';
};
$invalid = static fn (string $key): string => isset($proposalErrors[$key]) ? ' aria-invalid="true" aria-describedby="pr-' . $key . '-error"' : '';
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($route) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar às propostas</a>
    <a class="erp-btn erp-btn--primary" href="#prop-reply"><?= $icon('upload', 'erp-icon--sm') ?>Anexar proposta</a>
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
        <form class="erp-form" id="prop-quote" method="post" action="<?= $this->e($base . '/calculo') ?>" data-erp-loading data-proposal-quote data-units="<?= (int)$quote['units'] ?>">
            <?= csrf_input() ?>
            <h3 class="erp-proposal-message__title">Conta da proposta</h3>
            <p class="erp-muted">Só para conferência da equipe: não vai no e-mail. <?= (int)$quote['units'] ?> unidade(s).</p>
            <div class="erp-form__grid">
                <div class="erp-field erp-col-12"><label for="q-admin">Administradora (R$ por unidade)</label><input data-erp-money class="erp-input" id="q-admin" name="price_admin_unit" inputmode="decimal" placeholder="0,00" value="<?= $this->e($money($quote['admin_unit'])) ?>" data-quote="admin"></div>
                <div class="erp-field erp-col-12"><label for="q-syndic">Síndico profissional (R$ por unidade)</label><input data-erp-money class="erp-input" id="q-syndic" name="price_syndic_unit" inputmode="decimal" placeholder="0,00" value="<?= $this->e($money($quote['syndic_unit'])) ?>" data-quote="syndic"></div>
                <div class="erp-field erp-col-12"><label for="q-min">Mínimo que compensa (R$ por mês)</label><input data-erp-money class="erp-input" id="q-min" name="min_monthly" inputmode="decimal" placeholder="0,00" value="<?= $this->e($money($quote['min_monthly'])) ?>" data-quote="min"><span class="erp-field__hint">Opcional. Abaixo dele, a conta avisa que não compensa.</span></div>
            </div>
            <dl class="erp-detail-grid" aria-live="polite">
                <div><dt>Administradora no mês</dt><dd data-quote-out="admin"><?= $this->e($brl($quote['admin_monthly'])) ?></dd></div>
                <div><dt>Síndico no mês</dt><dd data-quote-out="syndic"><?= $this->e($brl($quote['syndic_monthly'])) ?></dd></div>
                <div><dt>Total no mês</dt><dd><strong data-quote-out="monthly"><?= $this->e($brl($quote['monthly'])) ?></strong></dd></div>
                <div><dt>Total no ano</dt><dd data-quote-out="yearly"><?= $this->e($brl($quote['yearly'])) ?></dd></div>
                <div><dt>Compensa?</dt><dd data-quote-out="worth"><?= $quote['worth'] === null ? '—' : ($quote['worth'] ? 'Sim, passa do mínimo' : 'Não, abaixo do mínimo') ?></dd></div>
            </dl>
            <div class="erp-form__actions"><button type="submit" class="erp-btn"><?= $icon('check', 'erp-icon--sm') ?>Gravar conta</button></div>
        </form>
    </aside>
</div>

<section class="erp-card" id="prop-reply" aria-labelledby="prop-reply-title">
    <div class="erp-card__head"><div><h2 id="prop-reply-title">Anexar e enviar a proposta</h2><p>Anexe os PDFs prontos (feitos no Canva, por exemplo). Pode anexar um ou os dois. Eles vão juntos no mesmo e-mail para <?= $this->e($proposal['email']) ?>.</p></div></div>
    <?php if (isset($proposalErrors['form'])): ?>
        <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($proposalErrors['form']) ?></div></div>
    <?php elseif ($proposalErrors !== []): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><?= $this->e(implode(' ', $proposalErrors)) ?></div></div>
    <?php endif; ?>
    <form class="erp-form" method="post" enctype="multipart/form-data" action="<?= $this->e($base . '/resposta') ?>" data-erp-confirm="Enviar esta proposta para <?= $this->e($proposal['email']) ?>? Os PDFs anexados vão por e-mail agora." data-confirm-undo="nao" data-confirm-undo-text="Depois de enviado, o e-mail não volta." data-confirm-action="Enviar" data-confirm-tone="primary">
        <?= csrf_input() ?>
        <div class="erp-form__grid">
            <?php foreach (ProposalComposer::KINDS as $kind => $label): ?>
                <div class="erp-field erp-col-6"><label for="pr-pdf-<?= $kind ?>">PDF da proposta: <?= $this->e($label) ?></label><input class="erp-input" type="file" id="pr-pdf-<?= $kind ?>" name="pdf_<?= $kind ?>" accept="application/pdf,.pdf" data-max-mb="20"<?= $invalid('pdf_' . $kind) ?>><span class="erp-field__hint">Só PDF, até 20 MB. Opcional se anexar o outro.</span><?= $error('pdf_' . $kind) ?></div>
            <?php endforeach; ?>
            <?php if (isset($proposalErrors['pdf'])): ?><div class="erp-field erp-col-12"><?= $error('pdf') ?></div><?php endif; ?>
            <div class="erp-field erp-col-8"><label for="pr-subject">Assunto do e-mail</label><input class="erp-input" id="pr-subject" name="subject" maxlength="255" required value="<?= $this->e($reply['subject']) ?>"<?= $invalid('subject') ?>><?= $error('subject') ?></div>
            <div class="erp-field erp-col-4"><label for="pr-valid">Validade da proposta</label><input class="erp-input" type="date" id="pr-valid" name="valid_until" min="<?= date('Y-m-d') ?>" required value="<?= $this->e($reply['valid_until']) ?>"<?= $invalid('valid_until') ?>><?= $error('valid_until') ?></div>
            <div class="erp-field erp-col-12"><label for="pr-intro">Mensagem no e-mail (opcional)</label><textarea class="erp-textarea" id="pr-intro" name="introduction" rows="4" maxlength="5000" placeholder="Ex.: Conforme conversamos por telefone, segue a proposta. Fico à disposição."><?= $this->e($reply['introduction']) ?></textarea><span class="erp-field__hint">Entra no e-mail, junto do texto padrão da Connect.</span></div>
        </div>
        <div class="erp-form__actions">
            <button type="submit" class="erp-btn" name="action" value="save" data-erp-no-confirm><?= $icon('upload', 'erp-icon--sm') ?>Só anexar (enviar depois)</button>
            <button type="submit" class="erp-btn erp-btn--primary" name="action" value="send"><?= $icon('check', 'erp-icon--sm') ?>Anexar e enviar por e-mail</button>
        </div>
    </form>
</section>

<section class="erp-card" id="prop-responses" aria-labelledby="prop-responses-title">
    <div class="erp-card__head"><div><h2 id="prop-responses-title">PDFs da proposta</h2><p>Cada PDF anexado, do mais novo para o mais antigo. Os gerados antes no Studio também aparecem aqui.</p></div><span class="erp-badge erp-badge--plain"><?= count($proposalResponses) ?></span></div>
    <?php if ($proposalResponses === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhum PDF anexado</strong><p>Use "Anexar e enviar a proposta" acima.</p></div>
    <?php else: ?>
        <ul class="erp-proposal-responses">
            <?php foreach ($proposalResponses as $response): ?>
                <li><strong><?= $this->e(ProposalComposer::KINDS[$response['template_type']] ?? 'Proposta') ?></strong><span class="erp-muted"><?= $this->e($response['subject'] . ' · ' . $when($response['created_at'])) ?></span><span class="erp-badge erp-badge--<?= in_array($response['status'], ['sent', 'queued'], true) ? 'success' : ($response['status'] === 'failed' ? 'danger' : 'plain') ?>"><?= $this->e($responseLabels[$response['status']] ?? $response['status']) ?></span><?php if ($response['has_pdf']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($base . '/pdf/' . $response['id']) ?>" target="_blank" rel="noopener"><?= $icon('eye', 'erp-icon--sm') ?>Abrir PDF</a><form method="post" action="<?= $this->e($base . '/reenviar') ?>" data-erp-confirm="Enviar este PDF de novo para <?= $this->e($proposal['email']) ?>?" data-confirm-undo="nao" data-confirm-undo-text="Depois de enviado, o e-mail não volta." data-confirm-tone="primary"><?= csrf_input() ?><input type="hidden" name="response_id" value="<?= (int)$response['id'] ?>"><button type="submit" class="erp-btn erp-btn--sm">Enviar de novo</button></form><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
<script>
(function () {
  // Conta da proposta ao vivo enquanto digita (a gravação e o cálculo oficial são no servidor).
  var form = document.querySelector('[data-proposal-quote]');
  if (!form) return;
  var units = parseInt(form.getAttribute('data-units'), 10) || 0;
  var cents = function (name) {
    var raw = (form.querySelector('[data-quote="' + name + '"]').value || '').trim();
    if (raw === '' || !/^\d{1,3}(\.?\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/.test(raw)) return null;
    var parts = raw.replace(/\./g, '').split(',');
    return parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
  };
  var brl = function (c) { return c === null ? '—' : 'R$ ' + (c / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var out = function (name, text) { var el = form.querySelector('[data-quote-out="' + name + '"]'); if (el) el.textContent = text; };
  var update = function () {
    var a = cents('admin'), s = cents('syndic'), m = cents('min');
    var am = a === null ? null : a * units, sm = s === null ? null : s * units;
    var total = am === null && sm === null ? null : (am || 0) + (sm || 0);
    out('admin', brl(am)); out('syndic', brl(sm)); out('monthly', brl(total)); out('yearly', brl(total === null ? null : total * 12));
    out('worth', total === null || m === null ? '—' : (total >= m ? 'Sim, passa do mínimo' : 'Não, abaixo do mínimo'));
  };
  form.addEventListener('input', update);
})();
</script>
