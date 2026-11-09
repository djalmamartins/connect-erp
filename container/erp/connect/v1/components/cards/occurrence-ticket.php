<?php
/**
 * Chamado do Help Desk de uma ocorrência (AP-112, ADR 0034, 0051 e 0070). Com chamado, mostra o
 * número e o link para a fila do Help Desk; sem chamado e com erp.occurrences.manage, o botão
 * "Abrir chamado" chama POST /api/v1/occurrences/{id}/ticket (erp.js, data-erp-occurrence-ticket).
 * @var array{ticketId:?int, href:?string, hrefPattern:string, api:string, canOpen:bool} $ticket
 */
?>
<section class="erp-card erp-occurrence-ticket" aria-labelledby="occ-ticket-title" data-erp-occurrence-ticket data-api="<?= $this->e($ticket['api']) ?>" data-href="<?= $this->e($ticket['hrefPattern']) ?>">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="occ-ticket-title">Chamado no Help Desk</h2><p>Para quando a ocorrência precisa da administradora</p></div></div>
    <div data-ticket-state>
        <?php if ($ticket['ticketId'] !== null): ?>
            <p class="erp-occurrence-ticket__status"><span class="erp-badge erp-badge--success">Chamado aberto</span> <strong>nº <?= (int)$ticket['ticketId'] ?></strong></p>
            <a class="erp-btn erp-btn--block" href="<?= $this->e((string)$ticket['href']) ?>" target="_blank" rel="noopener"><?= $icon('clipboard', 'erp-icon--sm') ?>Ver no Help Desk</a>
        <?php elseif ($ticket['canOpen']): ?>
            <p class="erp-field__hint">Abre um chamado na fila do Help Desk com o assunto, a descrição, a unidade e a prioridade desta ocorrência, e a ocorrência passa para "Em andamento". Cada ocorrência abre um chamado só.</p>
            <button type="button" class="erp-btn erp-btn--primary erp-btn--block" data-ticket-open><?= $icon('clipboard', 'erp-icon--sm') ?>Abrir chamado</button>
        <?php else: ?>
            <p class="erp-field__hint">Nenhum chamado aberto para esta ocorrência.</p>
        <?php endif; ?>
    </div>
</section>
