<?php
/**
 * Chamados do morador (ADR 0302 e 0305): toda ocorrência é um chamado do Help Desk, com número, situação e conversa.
 *
 * @var array{data:list<array>, meta:array{page:int, per_page:int, total:int}} $tickets ResidentTickets::list()
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$tones = ['open' => 'warning', 'in_progress' => 'info', 'waiting_customer' => 'danger', 'resolved' => 'success', 'closed' => 'neutral'];
$items = $tickets['data'];
$meta = $tickets['meta'];
$pages = max(1, (int)ceil($meta['total'] / max(1, $meta['per_page'])));
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Chamados</h1><p>Barulho, vazamento, dano ou qualquer problema no condomínio. Acompanhe a resposta e converse com a administração.</p></header>
<details class="app-card app-form-card"<?= $items === [] ? ' open' : '' ?>>
    <summary><h2>Abrir chamado</h2></summary>
    <form method="post" action="<?= app_url('/ocorrencias') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-field">Assunto <input type="text" name="subject" maxlength="160" required></label>
        <label class="app-field">O que aconteceu <textarea name="description" rows="4" maxlength="4000" required></textarea></label>
        <div class="app-field-row">
            <label class="app-field">Data <input type="date" name="occurred_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
            <label class="app-field">Urgência
                <select name="priority"><?php foreach (F::PRIORITIES as $key => $label): ?><option value="<?= $key ?>"<?= $key === 'media' ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button class="app-button" type="submit">Abrir chamado</button>
    </form>
</details>
<section class="app-section">
    <h2>Meus chamados</h2>
    <?php if ($items === []): ?><?php $this->insert('partials/empty', ['icon' => 'alert', 'text' => 'Nenhum chamado aberto por você neste condomínio.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($items as $t):
        $this->insert('partials/item', ['icon' => 'alert', 'tone' => $tones[$t['status']] ?? 'neutral', 'title' => $t['subject'],
            'lines' => ['Nº ' . $t['number'] . ' · aberto em ' . F::date(substr($t['created_at'], 0, 10)),
                $t['last_message_at'] ? 'Última mensagem em ' . F::dateTime($t['last_message_at']) : ''],
            'badge' => [$t['status_label'], $tones[$t['status']] ?? 'neutral'], 'href' => app_url('/ocorrencias/' . $t['id'])]);
    endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
        <nav class="app-pager" aria-label="Páginas">
            <?php if ($meta['page'] > 1): ?><a class="app-button app-button--ghost" href="<?= app_url('/ocorrencias') ?>?pagina=<?= $meta['page'] - 1 ?>">Mais recentes</a><?php endif; ?>
            <span><?= $meta['page'] ?> de <?= $pages ?></span>
            <?php if ($meta['page'] < $pages): ?><a class="app-button app-button--ghost" href="<?= app_url('/ocorrencias') ?>?pagina=<?= $meta['page'] + 1 ?>">Mais antigos</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
