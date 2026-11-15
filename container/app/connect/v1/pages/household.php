<?php
/**
 * Moradores da unidade (revisão de 15/11, etapa 4), igual ao app de celular. O proprietário cadastra inquilino e
 * morador e bloqueia inquilino; o inquilino cadastra morador; o morador só vê. Regras em ResidentHousehold.
 *
 * @var array $link
 * @var array{data:list<array>, meta:array{can_add:list<string>}} $household ResidentHousehold::list()
 * @var string|null $chosen vínculo já marcado no formulário (?novo=inquilino|morador)
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$canAdd = $household['meta']['can_add'];
$labels = ['tenant' => ['Inquilino', 'Aluga a unidade'], 'resident' => ['Morador', 'Família ou quem mora junto']];
$chosen = in_array($chosen ?? null, $canAdd, true) ? $chosen : ($canAdd[0] ?? null);
$icons = ['owner' => 'key', 'tenant' => 'user', 'resident' => 'users', 'financial' => 'receipt'];
?>
<header class="app-page-head"><span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span><h1>Moradores</h1><p>Quem está ligado à unidade <?= $this->e($link['unit_label']) ?>.</p></header>
<?php if ($canAdd !== []): ?>
<details class="app-card app-form-card"<?= !empty($chosen) && ($_GET['novo'] ?? '') !== '' ? ' open' : '' ?>>
    <summary><h2>Cadastrar <?= count($canAdd) > 1 ? 'inquilino ou morador' : 'morador' ?></h2></summary>
    <form method="post" action="<?= app_url('/minha-unidade/moradores') ?>" class="app-form">
        <?= csrf_input() ?>
        <?php if (count($canAdd) > 1): ?>
        <fieldset class="app-kind">
            <legend>Vínculo com a unidade</legend>
            <?php foreach ($canAdd as $key): ?>
                <label class="app-kind__option"><input type="radio" name="relationship" value="<?= $key ?>"<?= $chosen === $key ? ' checked' : '' ?>><span><strong><?= $labels[$key][0] ?></strong><small><?= $labels[$key][1] ?></small></span></label>
            <?php endforeach; ?>
        </fieldset>
        <?php else: ?>
        <input type="hidden" name="relationship" value="<?= $canAdd[0] ?>">
        <?php endif; ?>
        <label class="app-field">Nome completo <input type="text" name="name" maxlength="160" required autocomplete="off"></label>
        <label class="app-field">CPF (opcional) <input type="text" name="document" maxlength="18" inputmode="numeric" autocomplete="off"></label>
        <div class="app-field-row">
            <label class="app-field">E-mail (opcional) <input type="email" name="email" maxlength="254" autocomplete="off"></label>
            <label class="app-field">Celular (opcional) <input type="tel" name="phone" maxlength="20" autocomplete="off"></label>
        </div>
        <label class="app-field">Desde <input type="date" name="starts_on" value="<?= date('Y-m-d') ?>"></label>
        <button class="app-button" type="submit">Cadastrar</button>
        <p class="app-hint">O cadastro vai para a administração. Para a pessoa usar o aplicativo, a administração envia o convite.</p>
    </form>
</details>
<?php endif; ?>
<section class="app-section">
    <h2>Na unidade</h2>
    <?php if ($household['data'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'users', 'text' => 'Ninguém cadastrado nesta unidade.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($household['data'] as $p):
        $block = $p['can_block'] ? '<form method="post" action="' . app_url('/minha-unidade/moradores/' . $p['id'] . '/bloquear') . '" data-app-confirm="Bloquear ' . $this->e($p['name']) . '? O inquilino deixa de acessar o aplicativo.">' . csrf_input() . '<button class="app-link-button" type="submit">Bloquear</button></form>' : null;
        $since = $p['status'] === 'active' ? ($p['starts_on'] ? 'Desde ' . F::date($p['starts_on']) : '') : ($p['ends_on'] ? 'Até ' . F::date($p['ends_on']) : '');
        $this->insert('partials/item', ['icon' => $icons[$p['relationship']] ?? 'user', 'tone' => $p['status'] === 'active' ? 'brand' : 'neutral',
            'title' => $p['name'] . ($p['is_me'] ? ' (você)' : ''), 'lines' => [$p['relationship_label'] . ($since !== '' ? ' · ' . $since : '')],
            'badge' => [$p['status_label'], $p['status'] === 'active' ? 'success' : 'neutral'], 'actions' => $block]);
    endforeach; ?>
    </ul>
    <?php if (!in_array('tenant', $canAdd, true) && $link['relationship'] !== 'owner'): ?><p class="app-hint">Só o proprietário cadastra ou bloqueia inquilinos.</p><?php endif; ?>
</section>
