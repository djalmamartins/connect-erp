<?php
/**
 * Catálogo do checklist de chegada (ADR 0013).
 *
 * @var list<array<string,mixed>> $templates
 * @var array<string,mixed>|null $editing
 * @var bool $creating
 */

use Source\Services\OnboardingChecklist;

$this->layout('default');
$cssFile = dirname(__DIR__, 2) . '/assets/css/checklist.css';
$categories = OnboardingChecklist::CATEGORY_LABELS;
$base = url('/erp/checklist/catalogo');
$form = $editing ?? ($creating ? ['id' => null, 'code' => '', 'category' => 'documentacao', 'title' => '', 'description' => '', 'default_due_days' => 30, 'renewal_months' => null, 'position' => 100, 'active' => true, 'usage_count' => 0] : null);
?>
<link rel="stylesheet" href="<?= themeErp('/assets/css/checklist.css') . '?v=' . (is_file($cssFile) ? filemtime($cssFile) : 0) ?>">

<nav class="erp-breadcrumb" aria-label="Trilha">
    <a href="<?= url('/erp/condominios') ?>">Condomínios</a> <span aria-hidden="true">/</span>
    <span aria-current="page">Catálogo do checklist de chegada</span>
</nav>
<header class="erp-page-header">
    <h1>Catálogo do checklist de chegada</h1>
    <p>Itens que todo condomínio verifica ao chegar na Connect. Mudanças valem para os próximos checklists; nos condomínios que já têm checklist, use “Atualizar com o catálogo” para receber itens novos.</p>
</header>

<?= flash() ?>

<?php if ($form !== null): ?>
    <section class="chk-group" id="form" aria-labelledby="chk-form-title">
        <h2 id="chk-form-title"><?= $form['id'] ? 'Editar: ' . $this->e($form['title']) : 'Novo item do catálogo' ?></h2>
        <form method="post" action="<?= $form['id'] ? $base . '/' . (int)$form['id'] : $base ?>" class="chk-form erp-state">
            <?= csrf_input() ?>
            <?php if (!$form['id']): ?>
                <label>Código (sem espaços)
                    <input type="text" name="code" required pattern="[a-z0-9_]{2,60}" maxlength="60" placeholder="ex.: gas_laudo">
                </label>
            <?php endif; ?>
            <label>Categoria
                <select name="category" required>
                    <?php foreach ($categories as $value => $text): ?>
                        <option value="<?= $value ?>"<?= $form['category'] === $value ? ' selected' : '' ?>><?= $this->e($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="chk-wide">Nome do item
                <input type="text" name="title" required maxlength="160" value="<?= $this->e((string)$form['title']) ?>">
            </label>
            <label class="chk-wide">O que verificar
                <textarea name="description" rows="2" maxlength="500"><?= $this->e((string)$form['description']) ?></textarea>
            </label>
            <label>Prazo para regularizar (dias)
                <input type="number" name="default_due_days" min="1" max="365" required value="<?= (int)$form['default_due_days'] ?>">
            </label>
            <label>Renova a cada (meses)
                <input type="number" name="renewal_months" min="1" max="120" value="<?= $form['renewal_months'] !== null ? (int)$form['renewal_months'] : '' ?>" placeholder="em branco: não vence">
            </label>
            <label>Ordem na categoria
                <input type="number" name="position" min="0" max="999" value="<?= (int)$form['position'] ?>">
            </label>
            <label class="chk-check"><input type="checkbox" name="active" value="1"<?= $form['active'] ? ' checked' : '' ?>> Ativo (entra nos novos checklists)</label>
            <?php if ($form['id'] && $form['usage_count'] > 0): ?>
                <p class="chk-hint">Usado em <?= (int)$form['usage_count'] ?> condomínio(s). Os itens já criados mantêm o nome antigo; prazo e renovação novos valem nas próximas atualizações.</p>
            <?php endif; ?>
            <div class="chk-actions">
                <button type="submit" class="chk-button chk-button-primary">Salvar</button>
                <a href="<?= $base ?>">Cancelar</a>
            </div>
        </form>
    </section>
<?php else: ?>
    <p><a class="chk-button chk-button-primary" href="<?= $base ?>?novo=1#form">Novo item</a></p>
<?php endif; ?>

<div class="erp-table-wrap">
    <table class="erp-table chk-table">
        <caption class="erp-visually-hidden">Itens do catálogo (<?= count($templates) ?>)</caption>
        <thead>
        <tr><th scope="col">Item</th><th scope="col">Categoria</th><th scope="col">Prazo</th><th scope="col">Renovação</th><th scope="col">Situação</th><th scope="col">Em uso</th><th scope="col"><span class="erp-visually-hidden">Ações</span></th></tr>
        </thead>
        <tbody>
        <?php foreach ($templates as $template): ?>
            <tr id="tpl-<?= (int)$template['id'] ?>">
                <td><strong><?= $this->e($template['title']) ?></strong><br><small><code><?= $this->e($template['code']) ?></code></small></td>
                <td><?= $this->e($categories[$template['category']] ?? $template['category']) ?></td>
                <td><?= (int)$template['default_due_days'] ?> dias</td>
                <td><?= $template['renewal_months'] !== null ? (int)$template['renewal_months'] . ' meses' : 'Não vence' ?></td>
                <td><span class="chk-badge <?= $template['active'] ? 'chk-regular' : 'chk-not_applicable' ?>"><?= $template['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                <td><?= (int)$template['usage_count'] ?></td>
                <td><a href="<?= $base ?>?item=<?= (int)$template['id'] ?>#form">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
