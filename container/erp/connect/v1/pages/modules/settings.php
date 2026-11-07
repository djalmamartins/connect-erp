<?php
/** Configurações: seções de parâmetros ou matriz de permissões, com histórico. @var array $currentPage @var array $spec */
$this->layout('default');
$id = 'cfg-' . strtolower($currentPageId);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $currentPage['note'] ?: 'Alterações valem para os condomínios indicados e ficam registradas no histórico.',
]);
$this->insert('components/notifications/proposal');
?>
<div class="erp-grid erp-grid--main">
    <form class="erp-card erp-form" data-erp-validate data-erp-pending="Gravação pendente: as configurações serão salvas pelo servidor, com auditoria, quando o módulo estiver integrado. Nada foi alterado.">
        <?php if (!empty($spec['permissions'])): ?>
            <div class="erp-card__head"><div><h2>Permissões por perfil</h2><p>Marque o que cada perfil pode ver e alterar</p></div></div>
            <div class="erp-table-wrap" role="region" aria-label="Matriz de permissões" tabindex="0">
                <table class="erp-table">
                    <thead><tr><th scope="col">Módulo</th><?php foreach ($spec['roles'] as $role): ?><th scope="col"><?= $this->e($role) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($spec['modules'] as $m => $module): ?>
                            <tr><th scope="row"><?= $this->e($module) ?></th>
                                <?php foreach ($spec['roles'] as $r => $role): $cid = $id . '-' . $m . '-' . $r; ?>
                                    <td><label class="erp-check" for="<?= $cid ?>"><input type="checkbox" id="<?= $cid ?>"<?= ($r === 0 || ($m + $r) % 3 === 0) ? ' checked' : '' ?>><span class="erp-sr-only"><?= $this->e($role . ' pode acessar ' . $module) ?></span>Acessar</label></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="erp-field__hint">A autorização é sempre validada no servidor; esconder um menu não basta.</p>
        <?php else: ?>
            <?php foreach ($spec['sections'] as $s => $section): ?>
                <fieldset class="erp-fieldset">
                    <legend><?= $this->e($section['title']) ?></legend>
                    <div class="erp-form__grid">
                        <?php foreach ($section['fields'] as $field) { $this->insert('components/forms/field', ['field' => $field]); } ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="erp-form__actions">
            <button type="reset" class="erp-btn erp-btn--ghost">Desfazer alterações</button>
            <button type="submit" class="erp-btn erp-btn--primary">Salvar configurações</button>
        </div>
    </form>
    <aside class="erp-card">
        <div class="erp-card__head"><div><h2>Histórico de alterações</h2><p>Exemplo</p></div></div>
        <ul class="erp-list">
            <li><span class="erp-list__icon"><?= $icon('settings', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Parâmetro alterado</strong><small>Registro de auditoria de exemplo</small></div><span class="erp-list__meta">03/11/2026</span></li>
            <li><span class="erp-list__icon"><?= $icon('settings', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Configuração inicial</strong><small>Registro de auditoria de exemplo</small></div><span class="erp-list__meta">10/01/2026</span></li>
        </ul>
    </aside>
</div>
