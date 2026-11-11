<?php
/**
 * Acesso de imobiliárias e moradores com dados reais (ADR 0147, sobre o ADR 0090): AP-148 Usuários de
 * imobiliárias, AP-152 Criação de usuários para moradores (convites) e AP-153 Permissões de Moradores (portal).
 * Toda mudança vai pela API de AccessAdmin (erp.js, data-erp-access), com CSRF no cabeçalho; a API confere de
 * novo permissão e escopo. Contatos chegam mascarados.
 * @var array $currentPage @var string $accessKind @var ?int $accessContext @var ?string $accessError @var bool $accessNeedsCondo @var bool $canManageAccess
 * @var list<array> $agencies @var array<int, string> $scopeCondominiums @var list<array> $eligibleUsers
 * @var list<array> $invitations @var list<array> $invitableResidents @var list<array> $portalMatrix
 */
use Source\Controllers\Erp\Connect\AccessScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;
use Source\Services\Erp\ResidentInvitations;

$this->layout('default');
$kind = $accessKind;
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condo['name'] ?? 'o condomínio');
$api = static fn (string $path): string => url('/api/v1' . $path);
$leads = [
    'partners' => 'Imobiliárias parceiras e os usuários delas. O parceiro só consulta unidades e moradores dos condomínios concedidos, com contatos mascarados; nada de financeiro.',
    'invitations' => 'Convite por e-mail para o morador criar a própria conta. O convite vale ' . ResidentInvitations::VALID_DAYS . ' dias e é de uso único.',
    'portal' => 'O que o portal do morador mostra neste condomínio. Tudo começa desligado.',
];
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $leads[$kind],
    'pageActions' => '',
]);
?>
<?php if ($accessNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p><?= $kind === 'portal' ? 'O portal do morador é configurado por condomínio.' : 'Os convites são de um condomínio.' ?> Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<div class="erp-stack" data-erp-access data-condominium="<?= (int)($accessContext ?? 0) ?>">
    <?= csrf_input() ?>
    <?php if ($accessError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($accessError) ?></strong></div></div>
    <?php endif; ?>

    <?php if ($kind === 'partners'): ?>
        <?php if ($canManageAccess): ?>
            <form class="erp-card erp-form" data-access-form data-api="<?= $this->e($api('/partner-agencies')) ?>" data-done="Imobiliária cadastrada." aria-labelledby="ac-agency-title" novalidate>
                <div class="erp-card__head"><div><h2 id="ac-agency-title">Nova imobiliária</h2><p>Depois de cadastrar, vincule os usuários dela. A conta de cada usuário é criada em Studio › Usuários.</p></div></div>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="ac-name">Nome</label><input class="erp-input" id="ac-name" name="name" minlength="3" maxlength="160" required></div>
                    <div class="erp-field erp-col-6"><label for="ac-document">CNPJ</label><input class="erp-input" id="ac-document" name="document" inputmode="numeric" maxlength="18" required data-mask="cnpj"></div>
                    <div class="erp-field erp-col-6"><label for="ac-email">E-mail</label><input class="erp-input" type="email" id="ac-email" name="email" maxlength="254"></div>
                    <div class="erp-field erp-col-6"><label for="ac-phone">Telefone</label><input class="erp-input" type="tel" id="ac-phone" name="phone" maxlength="20" data-mask="phone"></div>
                </div>
                <div data-form-error aria-live="assertive"></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Cadastrar imobiliária</button></div>
            </form>
        <?php endif; ?>

        <?php if ($agencies === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong>Nenhuma imobiliária ainda</strong><p>Cadastre a imobiliária parceira para dar acesso de consulta aos condomínios.</p></div>
        <?php endif; ?>
        <?php foreach ($agencies as $ag): $active = $ag['status'] === 'active'; ?>
            <section class="erp-card" aria-labelledby="ac-ag-<?= (int)$ag['id'] ?>">
                <div class="erp-card__head"><div><h2 id="ac-ag-<?= (int)$ag['id'] ?>"><?= $this->e($ag['name']) ?></h2><p>CNPJ <?= $this->e((string)$ag['document']) ?><?= $ag['email'] !== '' ? ' · ' . $this->e((string)$ag['email']) : '' ?><?= $ag['phone'] !== '' ? ' · ' . $this->e((string)$ag['phone']) : '' ?></p></div>
                    <span class="erp-badge erp-badge--<?= $active ? 'success' : 'plain' ?>"><?= $active ? 'Ativa' : 'Inativa' ?></span></div>
                <?php if ($ag['users'] === []): ?>
                    <p class="erp-muted">Nenhum usuário vinculado.</p>
                <?php else: ?>
                    <div class="erp-table-wrap" role="region" aria-labelledby="ac-ag-<?= (int)$ag['id'] ?>" tabindex="0"><table class="erp-table">
                        <thead><tr><th scope="col">Usuário</th><th scope="col">E-mail</th><th scope="col">Condomínios</th><?php if ($canManageAccess): ?><th scope="col" class="is-num">Ações</th><?php endif; ?></tr></thead>
                        <tbody><?php foreach ($ag['users'] as $u): $available = array_diff_key($scopeCondominiums, array_flip($u['condominium_ids'])); ?><tr>
                            <td><?= $this->e($u['name']) ?></td>
                            <td><?= $this->e((string)$u['email']) ?></td>
                            <td><?php if ($u['condominium_ids'] === []): ?><span class="erp-muted">Nenhum</span><?php else: ?><div class="erp-chips"><?php foreach ($u['condominium_ids'] as $cid): if (!isset($scopeCondominiums[$cid])) continue; ?>
                                <span class="erp-chip"><?= $this->e($scopeCondominiums[$cid]) ?><?php if ($canManageAccess): ?> <button type="button" data-access-post data-api="<?= $this->e($api('/partner-users/' . (int)$u['user_id'] . '/condominiums/revoke')) ?>" data-body="<?= $this->e(json_encode(['condominium_id' => $cid])) ?>"
                                    data-confirm="Revogar o acesso de <?= $this->e($u['name']) ?> a <?= $this->e($scopeCondominiums[$cid]) ?>?" data-done="Acesso revogado." aria-label="Revogar <?= $this->e($scopeCondominiums[$cid]) ?>">×</button><?php endif; ?></span>
                            <?php endforeach; ?></div><?php endif; ?></td>
                            <?php if ($canManageAccess): ?><td class="is-num">
                                <?php if ($active && $available !== []): ?>
                                    <form class="erp-inline-form" data-access-form data-api="<?= $this->e($api('/partner-users/' . (int)$u['user_id'] . '/condominiums')) ?>" data-int="condominium_id" data-done="Condomínio concedido." novalidate>
                                        <label class="erp-sr-only" for="ac-grant-<?= (int)$u['user_id'] ?>">Condomínio para <?= $this->e($u['name']) ?></label>
                                        <select class="erp-select erp-select--sm" id="ac-grant-<?= (int)$u['user_id'] ?>" name="condominium_id" required><option value="">Conceder condomínio</option><?php foreach ($available as $cid => $cname): ?><option value="<?= (int)$cid ?>"><?= $this->e($cname) ?></option><?php endforeach; ?></select>
                                        <button type="submit" class="erp-btn erp-btn--sm">Conceder</button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-access-post data-api="<?= $this->e($api('/partner-users/' . (int)$u['user_id'] . '/unlink')) ?>" data-body="{}"
                                    data-confirm="Desvincular <?= $this->e($u['name']) ?>? A conta perde o papel de parceiro e todo o acesso a condomínios." data-done="Usuário desvinculado.">Desvincular</button>
                            </td><?php endif; ?>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
                <?php if ($canManageAccess && $active): ?>
                    <div class="erp-form__actions erp-access-agency-actions">
                        <?php if ($eligibleUsers !== []): ?>
                            <form class="erp-inline-form" data-access-form data-api="<?= $this->e($api('/partner-agencies/' . (int)$ag['id'] . '/users')) ?>" data-int="user_id" data-done="Usuário vinculado." novalidate>
                                <label class="erp-sr-only" for="ac-link-<?= (int)$ag['id'] ?>">Usuário para vincular a <?= $this->e($ag['name']) ?></label>
                                <select class="erp-select erp-select--sm" id="ac-link-<?= (int)$ag['id'] ?>" name="user_id" required><option value="">Vincular usuário</option><?php foreach ($eligibleUsers as $eu): ?><option value="<?= (int)$eu['id'] ?>"><?= $this->e($eu['label']) ?></option><?php endforeach; ?></select>
                                <button type="submit" class="erp-btn erp-btn--sm">Vincular</button>
                                <div data-form-error aria-live="assertive"></div>
                            </form>
                        <?php else: ?>
                            <span class="erp-muted">Nenhum usuário comum livre para vincular. Crie a conta em Studio › Usuários, sem papel.</span>
                        <?php endif; ?>
                        <button type="button" class="erp-btn erp-btn--sm erp-btn--danger" data-access-post data-api="<?= $this->e($api('/partner-agencies/' . (int)$ag['id'] . '/deactivate')) ?>" data-body="{}"
                            data-confirm="Desativar <?= $this->e($ag['name']) ?>? Todos os usuários dela perdem o acesso." data-done="Imobiliária desativada.">Desativar imobiliária</button>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($kind === 'invitations' && $accessError === null): $pendingIds = array_column(array_filter($invitations, static fn (array $i): bool => $i['status'] === 'pending'), 'resident_id'); ?>
        <?php if ($canManageAccess): ?>
            <form class="erp-card erp-form" data-access-form data-api="<?= $this->e($api('/resident-invitations')) ?>" data-condo-field data-done="Convites enviados para a fila." aria-labelledby="ac-invite-title" novalidate>
                <div class="erp-card__head"><div><h2 id="ac-invite-title">Convidar moradores</h2><p><?= $this->e($condoName) ?> · só morador ativo com e-mail e CPF/CNPJ válido no cadastro. Um convite novo substitui o pendente. Até <?= ResidentInvitations::MAX_PER_DAY ?> convites por dia.</p></div></div>
                <?php if ($invitableResidents === []): ?>
                    <p class="erp-field__hint">O condomínio não tem morador ativo. Cadastre em Cadastros › Pessoas.</p>
                <?php else: ?>
                    <fieldset class="erp-field erp-charge-units"><legend>Moradores <span class="erp-muted" data-access-count>(0 marcados)</span></legend>
                        <div class="erp-charge-units__grid erp-charge-units__grid--people"><?php foreach ($invitableResidents as $r): ?><label class="erp-check<?= $r['has_email'] ? '' : ' erp-muted' ?>"><input type="checkbox" name="resident_ids[]" value="<?= (int)$r['id'] ?>"<?= $r['has_email'] ? '' : ' disabled' ?>> <span><?= $this->e($r['unit_label'] . ' · ' . $r['name']) ?> <small class="erp-muted"><?= $this->e(Screens::RELATIONSHIPS[$r['relationship']] ?? $r['relationship']) ?> · <?= $r['has_email'] ? $this->e($r['email']) : 'sem e-mail' ?><?= in_array($r['id'], $pendingIds, true) ? ' · convite pendente' : '' ?></small></span></label><?php endforeach; ?></div>
                    </fieldset>
                    <p class="erp-field__hint">O convite sai pela fila de avisos. Fora de produção, os e-mails vão só para o log ou para a caixa de teste.</p>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('megaphone', 'erp-icon--sm') ?>Enviar convites</button></div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <section class="erp-card" aria-labelledby="ac-inv-title">
            <div class="erp-card__head"><div><h2 id="ac-inv-title">Convites</h2><p><?= $this->e($condoName) ?> · <?= count($invitations) ?> convite(s).</p></div></div>
            <?php if ($invitations === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong>Nenhum convite ainda</strong><p>Os convites enviados aparecem aqui com a situação.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="ac-inv-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Morador</th><th scope="col">E-mail</th><th scope="col">Enviado em</th><th scope="col">Vale até</th><th scope="col">Situação</th><?php if ($canManageAccess): ?><th scope="col" class="is-num">Ações</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($invitations as $i): [$label, $tone] = Screens::INVITATION_STATUS[$i['status']] ?? [$i['status'], 'plain']; ?><tr>
                        <td><?= $this->e($i['name']) ?></td><td><?= $this->e((string)$i['email']) ?></td>
                        <td class="erp-nowrap"><?= $this->e(RS::brDate($i['created_at'], true)) ?></td><td class="erp-nowrap"><?= $this->e(RS::brDate($i['expires_at'], true)) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span><?= $i['accepted_at'] ? '<br><span class="erp-muted">em ' . $this->e(RS::brDate((string)$i['accepted_at'], true)) . '</span>' : '' ?></td>
                        <?php if ($canManageAccess): ?><td class="is-num"><?php if ($i['status'] === 'pending'): ?><button type="button" class="erp-btn erp-btn--sm" data-access-post data-api="<?= $this->e($api('/resident-invitations/' . (int)$i['id'] . '/revoke')) ?>" data-body="{}"
                            data-confirm="Revogar o convite de <?= $this->e($i['name']) ?>?" data-done="Convite revogado." aria-label="Revogar o convite de <?= $this->e($i['name']) ?>">Revogar</button><?php else: ?><span class="erp-muted">—</span><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($kind === 'portal' && $accessError === null): ?>
        <section class="erp-card" aria-labelledby="ac-portal-title" data-portal-api="<?= $this->e($api('/resident-portal-permissions')) ?>">
            <div class="erp-card__head"><div><h2 id="ac-portal-title">Portal do morador</h2><p><?= $this->e($condoName) ?> · cada função muda na hora e fica na auditoria.</p></div></div>
            <div class="erp-table-wrap" role="region" aria-labelledby="ac-portal-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Função</th><th scope="col">No portal</th><th scope="col">Alterado em</th></tr></thead>
                <tbody><?php foreach ($portalMatrix as $f): ?><tr>
                    <td><?= $this->e($f['label']) ?></td>
                    <td><label class="erp-check"><input type="checkbox" data-portal-feature="<?= $this->e($f['feature']) ?>"<?= $f['enabled'] ? ' checked' : '' ?><?= $canManageAccess ? '' : ' disabled' ?>> <span data-portal-state><?= $f['enabled'] ? 'Ligado' : 'Desligado' ?></span><span class="erp-sr-only"> — <?= $this->e($f['label']) ?></span></label></td>
                    <td class="erp-nowrap"><?= $f['updated_at'] ? $this->e(RS::brDate((string)$f['updated_at'], true)) : '<span class="erp-muted">—</span>' ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="erp-field__hint">O portal do morador ainda está desligado no início da operação: esta matriz já vale quando ele for liberado.</p>
        </section>
    <?php endif; ?>
</div>
<?php endif; ?>
