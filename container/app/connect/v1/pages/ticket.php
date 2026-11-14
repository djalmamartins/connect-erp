<?php
/** Um chamado do morador com a conversa (ADR 0305). @var array $ticket ResidentTickets::show() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$tones = ['open' => 'warning', 'in_progress' => 'info', 'waiting_customer' => 'danger', 'resolved' => 'success', 'closed' => 'neutral'];
$closed = $ticket['status'] === 'closed';
?>
<header class="app-page-head">
    <a class="app-back" href="<?= app_url('/ocorrencias') ?>"><svg aria-hidden="true"><use href="#i-chevron"></use></svg>Chamados</a>
    <h1><?= $this->e($ticket['subject']) ?></h1>
    <p>Nº <?= $this->e($ticket['number']) ?> · aberto em <?= F::dateTime($ticket['created_at']) ?> · urgência <?= $this->e(mb_strtolower($ticket['priority_label'])) ?></p>
</header>
<p><span class="app-badge app-badge--<?= $tones[$ticket['status']] ?? 'neutral' ?>"><?= $this->e($ticket['status_label']) ?></span>
    <?php if ($ticket['status'] === 'waiting_customer'): ?><span class="app-hint"> A administração está esperando a sua resposta.</span><?php endif; ?></p>

<ol class="app-chat">
<?php foreach ($ticket['messages'] as $m): $mine = $m['author'] === 'morador'; ?>
    <li class="app-chat__msg<?= $mine ? ' app-chat__msg--mine' : '' ?>">
        <span class="app-chat__who"><?= $mine ? 'Você' : $this->e($m['author_name'] !== '' ? $m['author_name'] : 'Administração') ?> · <?= F::dateTime($m['created_at']) ?></span>
        <div class="app-chat__body"><?= nl2br($this->e($m['body'])) ?></div>
    </li>
<?php endforeach; ?>
</ol>

<?php if ($closed): ?>
    <?php $this->insert('partials/empty', ['icon' => 'check', 'text' => 'Este chamado foi fechado. Se precisar, abra um novo.', 'action' => ['Abrir chamado', app_url('/ocorrencias')]]) ?>
<?php else: ?>
    <form class="app-card app-chat__reply" method="post" action="<?= app_url('/ocorrencias/' . $ticket['id'] . '/mensagens') ?>">
        <?= csrf_input() ?>
        <label class="app-field">Sua mensagem <textarea name="body" rows="3" maxlength="4000" required placeholder="Escreva para a administração"></textarea></label>
        <button class="app-button" type="submit">Enviar</button>
    </form>
<?php endif; ?>
