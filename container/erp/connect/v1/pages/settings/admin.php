<?php
/**
 * Administração, relatórios enviados, usuários e permissões do ERP com dados reais (ADR 0150, sobre o ADR 0168):
 * AP-091 Administração, AP-100 Relatórios Enviados, AP-149 Usuários do Sistema e AP-150 Permissões de Usuários.
 * Toda mudança vai pela API de ErpAdmin (erp.js, data-erp-access), com CSRF no cabeçalho; a API confere de novo
 * permissão, nível do papel e escopo. E-mail e IP chegam mascarados. Conta, senha, papel e 2FA ficam no Studio.
 * @var array $currentPage @var string $adminKind @var ?string $adminError @var bool $canManageAdmin
 * @var array $overview @var ?array $sent @var array<int, string> $scopeCondominiums @var array $erpUsers
 * @var array $matrix @var ?int $selectedUser @var ?array $userPermissions
 */
use Source\Controllers\Erp\Connect\AdminScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;

$this->layout('default');
$kind = $adminKind;
$condo = \Source\Support\Erp\CondominiumContext::current();
$api = static fn (string $path): string => url('/api/v1' . $path);
$route = erp_url($currentPage['route']);
$leads = [
    'admin' => 'Os dados da administradora e um resumo dos condomínios do seu escopo.',
    'sent' => 'Os relatórios exportados em CSV no período, lidos da auditoria. É o mesmo registro que conta o limite de exportações por hora.',
    'users' => 'As contas com acesso aos condomínios do seu escopo. A conta, a senha, o papel e o 2FA ficam em Studio › Usuários; aqui você dá ou tira o acesso aos condomínios.',
    'permissions' => 'O que cada papel pode fazer no ERP e as exceções de cada usuário. Tudo começa fechado: sem papel ou sem permissão, a pessoa não entra.',
];
ob_start(); ?>
    <?php if (in_array($kind, ['admin', 'sent'], true)): ?><button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $leads[$kind],
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-access data-condominium="<?= (int)($condo['id'] ?? 0) ?>">
    <?= csrf_input() ?>
    <?php if ($adminError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($adminError) ?></strong></div></div>
    <?php endif; ?>

    <?php if ($kind === 'admin'): $a = $overview['administrator']; $s = $overview['summary']; ?>
        <section class="erp-card" aria-labelledby="ad-admin-title">
            <div class="erp-card__head"><div><h2 id="ad-admin-title">Administradora</h2><p>Os dados vêm de <a href="<?= $this->e(erp_url('/configuracoes/administrativo')) ?>">Configurações › Administrativo</a>.</p></div></div>
            <dl class="erp-detail-grid">
                <div><dt>Nome exibido</dt><dd><?= $this->e($a['display_name'] !== '' ? $a['display_name'] : '—') ?></dd></div>
                <div><dt>E-mail de atendimento</dt><dd><?= $this->e($a['support_email'] !== '' ? $a['support_email'] : '—') ?></dd></div>
                <div><dt>Telefone de atendimento</dt><dd><?= $this->e($a['support_phone'] !== '' ? $a['support_phone'] : '—') ?></dd></div>
            </dl>
        </section>
        <section class="erp-grid erp-grid--kpi" aria-label="Resumo do escopo">
            <?php foreach ([['Condomínios', $s['condominiums'], 'building'], ['Unidades ativas', $s['units'], 'building'], ['Moradores ativos', $s['residents'], 'users'], ['Usuários com acesso', $s['users'], 'user'],
                ['Contas bancárias ativas', $s['bank_accounts'], 'wallet'], ['Fechados em ' . $overview['previous_month'], $s['closed_previous_month'], 'lock'], ['Em aberto em ' . $overview['previous_month'], $s['open_previous_month'], 'calendar']] as $i => [$label, $value, $kpiIcon]):
                $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => number_format((int)$value, 0, ',', '.'), 'meta' => $overview['global_access'] ? 'Todos os condomínios' : 'Condomínios do seu escopo',
                    'icon' => $kpiIcon, 'tone' => str_starts_with($label, 'Em aberto') && (int)$value > 0 ? 'warning' : '', 'trend' => null], 'delay' => $i * 40]);
            endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($kind === 'sent' && $sent !== null): $t = $sent['totals']; ?>
        <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtros do relatório">
            <div class="erp-field erp-field--compact"><label for="ad-de">De</label><input class="erp-input" type="date" id="ad-de" name="de" value="<?= $this->e($sent['from']) ?>" required></div>
            <div class="erp-field erp-field--compact"><label for="ad-ate">Até</label><input class="erp-input" type="date" id="ad-ate" name="ate" value="<?= $this->e($sent['to']) ?>" required></div>
            <div class="erp-field erp-field--compact"><label for="ad-relatorio">Relatório</label><select class="erp-select" id="ad-relatorio" name="relatorio"><option value="">Todos</option>
                <?php foreach (array_keys($t['by_report']) as $rep): if ($rep === '') continue; ?><option value="<?= $this->e($rep) ?>"<?= $sent['report'] === $rep ? ' selected' : '' ?>><?= $this->e($rep) ?></option><?php endforeach; ?>
                <?php if ($sent['report'] !== null && !isset($t['by_report'][$sent['report']])): ?><option value="<?= $this->e($sent['report']) ?>" selected><?= $this->e($sent['report']) ?></option><?php endif; ?>
            </select></div>
            <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
        </form>
        <div class="erp-alert" role="note"><?= $icon('lock') ?><div>Nome e IP aparecem mascarados. Quem não acessa todos os condomínios vê só as exportações dos condomínios do seu escopo e as próprias.</div></div>
        <?php if ($sent['truncated']): ?>
            <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong>Lista cortada</strong>O período tem mais exportações do que a tela mostra. Diminua o período.</div></div>
        <?php endif; ?>
        <section class="erp-card" aria-labelledby="ad-sent-title">
            <div class="erp-card__head"><div><h2 id="ad-sent-title">Exportações</h2><p>De <?= $this->e(RS::brDate($sent['from'])) ?> a <?= $this->e(RS::brDate($sent['to'])) ?> · <?= (int)$t['exports'] ?> exportação(ões) · <?= $this->e(Screens::size((int)$t['bytes'])) ?></p></div></div>
            <?php if ($sent['rows'] === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nada neste período</strong><p>Nenhum relatório foi exportado nos filtros escolhidos.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="ad-sent-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Data</th><th scope="col">Usuário</th><th scope="col">Relatório</th><th scope="col">Condomínio</th><th scope="col" class="is-num">Tamanho</th><th scope="col">IP</th></tr></thead>
                    <tbody><?php foreach ($sent['rows'] as $r): ?><tr>
                        <td class="erp-nowrap"><?= $this->e(RS::brDate($r['created_at'], true)) ?></td>
                        <td><?= $this->e($r['user_name']) ?></td>
                        <td><?= $this->e($r['report'] !== '' ? $r['report'] : $r['entity']) ?></td>
                        <td><?= $r['condominium_id'] !== null ? $this->e($scopeCondominiums[$r['condominium_id']] ?? ('Condomínio nº ' . $r['condominium_id'])) : '<span class="erp-muted">—</span>' ?></td>
                        <td class="is-num erp-nowrap"><?= $this->e(Screens::size($r['bytes'])) ?></td>
                        <td class="erp-nowrap"><?= $this->e((string)$r['ip']) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($kind === 'users'): $rows = $erpUsers['rows']; ?>
        <?php if ($canManageAdmin && $scopeCondominiums !== []): ?>
            <form class="erp-card erp-form" data-access-form data-api="<?= $this->e($api('/erp-users/invite')) ?>" data-done="Acesso concedido." aria-labelledby="ad-invite-title" novalidate>
                <div class="erp-card__head"><div><h2 id="ad-invite-title">Dar acesso a uma conta</h2><p>A conta precisa existir em Studio › Usuários, com um papel que entre no ERP. Aqui você só escolhe os condomínios.</p></div></div>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="ad-email">E-mail da conta</label><input class="erp-input" type="email" id="ad-email" name="email" maxlength="254" required autocomplete="off"></div>
                </div>
                <fieldset class="erp-field erp-charge-units"><legend>Condomínios <span class="erp-muted" data-access-count>(0 marcados)</span></legend>
                    <div class="erp-charge-units__grid"><?php foreach ($scopeCondominiums as $cid => $cname): ?><label class="erp-check"><input type="checkbox" name="condominium_ids[]" value="<?= (int)$cid ?>"<?= (int)($condo['id'] ?? 0) === (int)$cid ? ' checked' : '' ?>> <?= $this->e($cname) ?></label><?php endforeach; ?></div>
                </fieldset>
                <div data-form-error aria-live="assertive"></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Dar acesso</button></div>
            </form>
        <?php endif; ?>
        <section class="erp-card" aria-labelledby="ad-users-title">
            <div class="erp-card__head"><div><h2 id="ad-users-title">Usuários</h2><p><?= $this->e($condo !== null ? (string)$condo['name'] : 'Todos os condomínios do seu escopo') ?> · <?= count($rows) ?> usuário(s).</p></div></div>
            <?php if ($erpUsers['truncated']): ?><div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong>Lista cortada</strong>Escolha um condomínio na barra superior para ver todos os usuários dele.</div></div><?php endif; ?>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong>Nenhum usuário com acesso</strong><p>Dê acesso a uma conta do Studio para ela aparecer aqui.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="ad-users-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Usuário</th><th scope="col">E-mail</th><th scope="col">Papel</th><th scope="col">Condomínios</th><?php if ($canManageAdmin): ?><th scope="col" class="is-num">Ações</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($rows as $u): $can = $canManageAdmin && $u['manageable']; ?><tr>
                        <td><?= $this->e($u['name']) ?></td>
                        <td><?= $this->e((string)$u['email']) ?></td>
                        <td><?= $u['role_name'] !== null ? $this->e((string)$u['role_name']) : '<span class="erp-muted">Sem papel</span>' ?></td>
                        <td><div class="erp-chips"><?php foreach ($u['condominiums'] as $c): ?><span class="erp-chip"><?= $this->e($c['name']) ?><?php if ($can): ?> <button type="button" data-access-post data-api="<?= $this->e($api('/erp-users/' . (int)$u['id'] . '/deactivate')) ?>" data-body="<?= $this->e(json_encode(['condominium_id' => (int)$c['id']])) ?>"
                            data-confirm="Tirar o acesso de <?= $this->e($u['name']) ?> a <?= $this->e($c['name']) ?>?" data-done="Acesso retirado." aria-label="Tirar o acesso a <?= $this->e($c['name']) ?>">×</button><?php endif; ?></span><?php endforeach; ?></div></td>
                        <?php if ($canManageAdmin): ?><td class="is-num"><?php if ($can): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-access-post data-api="<?= $this->e($api('/erp-users/' . (int)$u['id'] . '/deactivate')) ?>" data-body="{}"
                            data-confirm="Tirar o acesso de <?= $this->e($u['name']) ?> a todos os condomínios do seu escopo? A conta continua existindo no Studio." data-done="Acesso retirado." aria-label="Tirar todo o acesso de <?= $this->e($u['name']) ?>">Tirar todo o acesso</button><?php else: ?><span class="erp-muted" title="Papel igual ou acima do seu, protegido ou a sua própria conta">—</span><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($kind === 'permissions'): $roles = $matrix['roles']; $perms = $matrix['permissions']; $editRoles = $canManageAdmin && $matrix['editable_roles']; ?>
        <section class="erp-card" aria-labelledby="ad-matrix-title">
            <div class="erp-card__head"><div><h2 id="ad-matrix-title">Permissões por papel</h2><p>Cada caixa grava na hora e fica na auditoria.<?= $canManageAdmin && !$matrix['editable_roles'] ? ' O papel vale para todos os condomínios: só quem acessa todos pode mudar.' : '' ?></p></div></div>
            <?php if ($roles === [] || $perms === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('shield') ?></span><strong>Nada para mostrar</strong><p>Não há papéis ou permissões do ERP visíveis para o seu nível.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="ad-matrix-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Permissão</th><?php foreach ($roles as $r): ?><th scope="col"><?= $this->e($r['name']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody><?php $group = null; foreach ($perms as $p): if ($p['group'] !== $group): $group = $p['group']; ?><tr><th scope="colgroup" colspan="<?= count($roles) + 1 ?>"><?= $this->e($group) ?></th></tr><?php endif; ?><tr>
                        <th scope="row"><?= $this->e($p['name']) ?><br><small class="erp-muted"><?= $this->e($p['slug']) ?></small></th>
                        <?php foreach ($roles as $r): $on = in_array($p['slug'], $r['permissions'], true); ?><td><label class="erp-check"><input type="checkbox" data-api-toggle data-api="<?= $this->e($api('/erp-permissions')) ?>" data-body="<?= $this->e(json_encode(['role' => $r['slug'], 'permission' => $p['slug']])) ?>"<?= $on ? ' checked' : '' ?><?= $editRoles && $r['editable'] ? '' : ' disabled' ?>><span class="erp-sr-only"><?= $this->e($p['name'] . ' — ' . $r['name']) ?></span></label></td><?php endforeach; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="erp-card" aria-labelledby="ad-user-perm-title">
            <div class="erp-card__head"><div><h2 id="ad-user-perm-title">Exceções por usuário</h2><p>Libera ou bloqueia uma permissão só para uma pessoa, acima do papel. Bloquear sempre vence.</p></div></div>
            <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Escolher usuário">
                <div class="erp-field erp-field--compact"><label for="ad-usuario">Usuário</label><select class="erp-select" id="ad-usuario" name="usuario"><option value="">Escolha</option>
                    <?php foreach ($erpUsers['rows'] as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $selectedUser === (int)$u['id'] ? ' selected' : '' ?>><?= $this->e($u['name'] . ' · ' . $u['email']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('search', 'erp-icon--sm') ?>Ver</button></div>
            </form>
            <?php if ($userPermissions !== null): $editUser = $canManageAdmin && $userPermissions['manageable']; ?>
                <?php if ($canManageAdmin && !$userPermissions['manageable']): ?><p class="erp-field__hint">Este usuário tem papel igual ou acima do seu, protegido ou é você: só consulta.</p><?php endif; ?>
                <div class="erp-table-wrap" role="region" aria-label="Permissões do usuário" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Permissão</th><th scope="col">Pelo papel</th><th scope="col">Exceção</th><th scope="col">Vale</th></tr></thead>
                    <tbody><?php foreach ($userPermissions['rows'] as $p): $effect = Screens::effect($p['override']); ?><tr>
                        <th scope="row"><?= $this->e($p['name']) ?><br><small class="erp-muted"><?= $this->e($p['slug']) ?></small></th>
                        <td><?= $p['role'] ? 'Sim' : '<span class="erp-muted">Não</span>' ?></td>
                        <td><label class="erp-sr-only" for="ad-ov-<?= $this->e($p['slug']) ?>">Exceção de <?= $this->e($p['name']) ?></label><select class="erp-select erp-select--sm" id="ad-ov-<?= $this->e($p['slug']) ?>" data-api-select data-api="<?= $this->e($api('/erp-users/' . (int)$userPermissions['user_id'] . '/permissions')) ?>" data-body="<?= $this->e(json_encode(['permission' => $p['slug']])) ?>"<?= $editUser ? '' : ' disabled' ?>>
                            <?php foreach (Screens::EFFECTS as $value => $label): ?><option value="<?= $value ?>"<?= $effect === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                        </select></td>
                        <td><span class="erp-badge erp-badge--<?= $p['effective'] ? 'success' : 'plain' ?>"><?= $p['effective'] ? 'Liberada' : 'Fechada' ?></span></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
                <p class="erp-field__hint">A coluna "Vale" mostra o que valia ao abrir a tela; depois de mudar uma exceção, atualize a página para ver o efeito.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
