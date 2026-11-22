<?php
/** Acompanhamento público do chamado (ADR 0636): link que a Jack manda pelo WhatsApp. */
$this->layout('layouts/theme');
$when = static fn (string $at): string => date('d/m/Y \à\s H:i', strtotime($at));
?>
<div class="hc-wrap hc-page">
    <nav class="hc-crumbs" aria-label="Você está em">
        <a href="<?= url('/suporte') ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>Início</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <span aria-current="page">Acompanhar chamado</span>
    </nav>
    <div class="hc-layout">
        <div class="hc-main">
            <?php if ($ticket === null): ?>
                <header class="hc-title"><i class="hc-title__icon"><?= \Source\Services\Help\Icons::svg('headset-outline') ?></i><h1>Chamado não encontrado</h1></header>
                <div class="hc-empty"><?= $limited
                    ? 'Muitas consultas seguidas. Espere alguns minutos e abra o link de novo.'
                    : 'O link está incompleto ou não vale mais. Mande o número do chamado no WhatsApp da Connect que a gente te conta como ele está.' ?></div>
            <?php else: ?>
                <header class="hc-title"><i class="hc-title__icon"><?= \Source\Services\Help\Icons::svg('headset-outline') ?></i><h1>Chamado <?= htmlspecialchars($ticket['protocol']) ?></h1></header>
                <section class="hc-track">
                    <p class="hc-track__status hc-track__status--<?= htmlspecialchars($ticket['status']) ?>"><?= htmlspecialchars($ticket['status_label']) ?></p>
                    <dl class="hc-track__facts">
                        <dt>Assunto</dt><dd><?= htmlspecialchars($ticket['subject']) ?></dd>
                        <dt>Área</dt><dd><?= htmlspecialchars($ticket['area_label']) ?></dd>
                        <dt>Aberto em</dt><dd><?= $when($ticket['created_at']) ?></dd>
                        <dt>Última atualização</dt><dd><?= $when($ticket['updated_at']) ?></dd>
                    </dl>
                </section>
                <h2 class="hc-track__h">Respostas</h2>
                <?php if (!$ticket['messages']): ?>
                    <div class="hc-empty">Ainda não há resposta. A equipe já recebeu o seu pedido e responde por aqui e pelo WhatsApp.</div>
                <?php endif; ?>
                <ol class="hc-track__msgs">
                    <?php foreach ($ticket['messages'] as $m): ?>
                        <li class="<?= $m['mine'] ? 'mine' : 'team' ?>">
                            <b><?= htmlspecialchars($m['author']) ?></b> <small><?= $when($m['created_at']) ?></small>
                            <p><?= nl2br(htmlspecialchars($m['body'])) ?></p>
                            <?php if ($m['attachments'] > 0): ?><small><?= $m['attachments'] === 1 ? '1 anexo' : $m['attachments'] . ' anexos' ?> no chamado</small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="hc-muted"><?= $ticket['closed']
                    ? 'Este chamado foi concluído. Se precisar de mais alguma coisa, é só mandar mensagem no WhatsApp.'
                    : 'Para responder, use o WhatsApp da Connect. Esta página mostra a situação atualizada sempre que você abrir o link.' ?></p>
            <?php endif; ?>
        </div>
        <aside class="hc-side">
            <section class="hc-side__box">
                <h2><?= \Source\Services\Help\Icons::svg('albums-outline') ?>Assuntos</h2>
                <ul class="hc-dots"><?php foreach ($categories as $item): ?><li><a href="<?= url('/suporte/' . $item->uri) ?>"><?= htmlspecialchars($item->title) ?></a></li><?php endforeach; ?></ul>
            </section>
        </aside>
    </div>
</div>
