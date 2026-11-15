<?php
/**
 * Chamados do morador (ADR 0302 e 0305): toda ocorrência é um chamado do Help Desk, com número, situação e conversa.
 *
 * Etapa 3 da revisão de 15/11: o morador escolhe Chamado (pedido ou dúvida) ou Ocorrência (problema no condomínio);
 * os dois vão para o Help Desk. A lista mostra os dois, com filtro.
 *
 * @var array{data:list<array>, meta:array{page:int, per_page:int, total:int}} $tickets ResidentTickets::list()
 * @var string|null $kind filtro (?tipo=chamado|ocorrencia), também o tipo já marcado no formulário
 * @var bool $open formulário aberto (?abrir=1, vindo do Resolva fácil)
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$tones = ['open' => 'warning', 'in_progress' => 'info', 'waiting_customer' => 'danger', 'resolved' => 'success', 'closed' => 'neutral'];
$items = $tickets['data'];
$meta = $tickets['meta'];
$pages = max(1, (int)ceil($meta['total'] / max(1, $meta['per_page'])));
$kind = $kind ?? null;
$chosen = $kind ?? 'chamado';
$kinds = ['chamado' => ['Chamado', 'Pedido ou dúvida à administração'], 'ocorrencia' => ['Ocorrência', 'Barulho, vazamento, dano ou outro problema']];
$query = static fn (array $q): string => ($q = array_filter($q, static fn ($v): bool => $v !== null && $v !== '')) ? '?' . http_build_query($q) : '';
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Chamados e ocorrências</h1><p>Peça algo à administração ou avise de um problema no condomínio. Acompanhe a resposta e converse por aqui.</p></header>
<details class="app-card app-form-card"<?= $items === [] || !empty($open) ? ' open' : '' ?>>
    <summary><h2>Abrir chamado ou ocorrência</h2></summary>
    <form method="post" action="<?= app_url('/ocorrencias') ?>" class="app-form">
        <?= csrf_input() ?>
        <fieldset class="app-kind">
            <legend>O que você quer registrar?</legend>
            <?php foreach ($kinds as $key => [$label, $hint]): ?>
                <label class="app-kind__option"><input type="radio" name="kind" value="<?= $key ?>"<?= $chosen === $key ? ' checked' : '' ?>><span><strong><?= $label ?></strong><small><?= $hint ?></small></span></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="app-field">Assunto <input type="text" name="subject" maxlength="160" required></label>
        <label class="app-field">Descreva <textarea name="description" rows="4" maxlength="4000" required></textarea></label>
        <div class="app-field-row">
            <label class="app-field">Data <input type="date" name="occurred_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
            <label class="app-field">Urgência
                <select name="priority"><?php foreach (F::PRIORITIES as $key => $label): ?><option value="<?= $key ?>"<?= $key === 'media' ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button class="app-button" type="submit">Enviar</button>
    </form>
</details>
<section class="app-section">
    <h2>Meus registros</h2>
    <nav class="app-chips" aria-label="Filtrar por tipo">
        <?php foreach (['' => 'Todos', 'chamado' => 'Chamados', 'ocorrencia' => 'Ocorrências'] as $key => $label): $on = (string)$kind === $key; ?>
            <a class="app-chip<?= $on ? ' is-active' : '' ?>" href="<?= app_url('/ocorrencias') . $query(['tipo' => $key]) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($items === []): ?><?php $this->insert('partials/empty', ['icon' => 'alert', 'text' => $kind === null ? 'Nada aberto por você neste condomínio.' : 'Nada deste tipo aberto por você neste condomínio.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($items as $t):
        $this->insert('partials/item', ['icon' => $t['kind'] === 'chamado' ? 'alert' : 'flag', 'tone' => $tones[$t['status']] ?? 'neutral', 'title' => $t['subject'],
            'lines' => [$kinds[$t['kind']][0] . ' nº ' . $t['number'] . ' · aberto em ' . F::date(substr($t['created_at'], 0, 10)),
                $t['last_message_at'] ? 'Última mensagem em ' . F::dateTime($t['last_message_at']) : ''],
            'badge' => [$t['status_label'], $tones[$t['status']] ?? 'neutral'], 'href' => app_url('/ocorrencias/' . $t['id'])]);
    endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
        <nav class="app-pager" aria-label="Páginas">
            <?php if ($meta['page'] > 1): ?><a class="app-button app-button--ghost" href="<?= app_url('/ocorrencias') . $query(['tipo' => $kind, 'pagina' => $meta['page'] - 1]) ?>">Mais recentes</a><?php endif; ?>
            <span><?= $meta['page'] ?> de <?= $pages ?></span>
            <?php if ($meta['page'] < $pages): ?><a class="app-button app-button--ghost" href="<?= app_url('/ocorrencias') . $query(['tipo' => $kind, 'pagina' => $meta['page'] + 1]) ?>">Mais antigos</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
