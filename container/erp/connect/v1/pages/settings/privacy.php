<?php
/**
 * Dados pessoais (CN-009, ADR 0332): pedidos do titular pela LGPD. Busca por CPF/CNPJ e/ou e-mail (POST, para não
 * ficar na URL), mostra o que o sistema guarda da pessoa nos condomínios do escopo, baixa o relatório em JSON e,
 * para quem tem erp.users.manage, anonimiza quem já saiu (prévia, motivo e confirmação; não tem volta).
 * @var array $currentPage @var string $privacyDocument @var string $privacyEmail @var ?array $privacyReport
 * @var ?array $privacyPreview @var ?string $privacyError @var bool $canAnonymize @var bool $privacyDatabase
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$day = static fn (?string $v): string => $v ? date('d/m/Y', (int)strtotime($v)) : '—';
$groups = ['residents' => 'Cadastros de morador, proprietário ou inquilino', 'invitations' => 'Convites do portal do morador', 'visitors' => 'Visitantes',
    'vehicles' => 'Veículos', 'pets' => 'Animais', 'receivables' => 'Contas a receber (pagador)', 'tickets' => 'Chamados do Help Desk',
    'access_logs' => 'Entradas e saídas na portaria', 'visit_forecasts' => 'Visitas previstas', 'board_members' => 'Conselho e síndico',
    'moves' => 'Mudanças', 'reservations' => 'Reservas', 'waitlist' => 'Lista de espera', 'assembly_attendance' => 'Presença em assembleia',
    'collection_contacts' => 'Contatos de cobrança'];
$relations = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];
$hidden = static function () use ($privacyDocument, $privacyEmail): string {
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="document" value="' . $e($privacyDocument) . '"><input type="hidden" name="email" value="' . $e($privacyEmail) . '">';
};

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Atenda os pedidos dos titulares pela LGPD: veja tudo o que o sistema guarda de uma pessoa, entregue o relatório e anonimize quem já saiu do condomínio.',
    'pageActions' => '',
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>
<?php if ($privacyError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($privacyError) ?></div></div>
<?php endif; ?>

<?php if (!$privacyDatabase): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('shield') ?></span><strong>Dados pessoais indisponíveis</strong><p>Sem conexão com o banco de dados, a busca não pode ser feita.</p></div></section>
<?php else: ?>
<section class="erp-card" aria-labelledby="privacy-search-title">
    <div class="erp-card__head"><div><h2 id="privacy-search-title">Buscar titular</h2><p>Confira a identidade de quem pediu antes de buscar. A busca fica no histórico do sistema, sem o CPF e sem o e-mail.</p></div></div>
    <form method="post" action="<?= $this->e($route) ?>" class="erp-form" data-privacy-search>
        <?= csrf_input() ?>
        <div class="erp-form__grid">
            <div class="erp-field"><label for="privacy-document">CPF ou CNPJ</label><input class="erp-input" id="privacy-document" name="document" inputmode="numeric" maxlength="32" autocomplete="off" value="<?= $this->e($privacyDocument) ?>"></div>
            <div class="erp-field"><label for="privacy-email">E-mail</label><input class="erp-input" type="email" id="privacy-email" name="email" maxlength="190" autocomplete="off" value="<?= $this->e($privacyEmail) ?>"></div>
        </div>
        <p class="erp-muted">Preencha um dos dois, ou os dois.</p>
        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('search', 'erp-icon--sm') ?>Buscar</button></div>
    </form>
</section>

<?php if ($privacyReport !== null): $r = $privacyReport; ?>
<section class="erp-card" aria-labelledby="privacy-report-title" style="margin-top:16px" data-privacy-report>
    <div class="erp-card__head">
        <div><h2 id="privacy-report-title">O que o sistema guarda</h2><p><?= (int)$r['total'] ?> registro(s) nos condomínios que você acessa.</p></div>
        <?php if ((int)$r['total'] > 0): ?>
            <form method="post" action="<?= $this->e($route . '/baixar') ?>"><?= csrf_input() ?><?= $hidden() ?><button type="submit" class="erp-btn"><?= $icon('download', 'erp-icon--sm') ?>Baixar relatório (JSON)</button></form>
        <?php endif; ?>
    </div>
    <?php if ((int)$r['total'] === 0): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('search') ?></span><strong>Nada encontrado</strong><p>O sistema não guarda dados dessa pessoa nos condomínios que você acessa.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="privacy-report-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Onde</th><th scope="col" class="is-num">Registros</th></tr></thead>
            <tbody>
                <?php foreach ($groups as $key => $label): $rows = $r[$key]['rows'] ?? []; ?>
                    <tr><td><?= $this->e($label) ?><?= !empty($r[$key]['truncated']) ? ' <span class="erp-muted">(mostrando os primeiros)</span>' : '' ?></td><td class="is-num"><?= count($rows) ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Conta de acesso ao sistema</td><td class="is-num"><?= $r['account'] !== null ? 1 : 0 ?></td></tr>
            </tbody>
        </table></div>
        <?php if (($r['residents']['rows'] ?? []) !== []): ?>
            <h3 style="margin-top:16px">Cadastros</h3>
            <div class="erp-table-wrap" role="region" aria-label="Cadastros do titular" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Nome</th><th scope="col">Condomínio e unidade</th><th scope="col">Vínculo</th><th scope="col">Situação</th><th scope="col">Saída</th></tr></thead>
                <tbody><?php foreach ($r['residents']['rows'] as $p): ?><tr>
                    <td><strong><?= $this->e($p['name']) ?></strong></td>
                    <td><?= $this->e($p['condominium'] ?? '') ?> · <?= $this->e($p['unit_label'] ?? '') ?></td>
                    <td><?= $this->e($relations[$p['relationship'] ?? ''] ?? (string)($p['relationship'] ?? '')) ?></td>
                    <td><?= !empty($p['deleted_at']) ? 'Excluído' : ((string)$p['status'] === 'active' ? 'Ativo' : 'Inativo') ?></td>
                    <td><?= $this->e($day($p['ends_on'] ?? null)) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canAnonymize && $privacyPreview !== null): $plan = $privacyPreview['plan']; $blockers = $privacyPreview['blockers']; ?>
<section class="erp-card" aria-labelledby="privacy-anon-title" style="margin-top:16px" data-privacy-anonymize>
    <div class="erp-card__head"><div><h2 id="privacy-anon-title">Anonimizar</h2><p>Troca nome, documento, e-mail e telefone por "Titular anonimizado". As contas a receber continuam guardadas, porque a lei obriga a manter a prestação de contas.</p></div></div>
    <?php if ($blockers !== []): ?>
        <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>Ainda não dá para anonimizar.</strong><ul><?php foreach ($blockers as $b): ?><li><?= $this->e($b['message']) ?></li><?php endforeach; ?></ul>Encerre o vínculo com a data de saída e quite ou baixe as contas em aberto antes.</div></div>
    <?php else: ?>
        <p>Vai mudar: <?= count($plan['residents']) ?> cadastro(s), <?= count($plan['invitations']) ?> convite(s) pendente(s), <?= count($plan['visitors']) ?> visitante(s), <?= count($plan['vehicles']) ?> veículo(s), <?= count($plan['pets']) ?> animal(is), <?= count($plan['tickets']) ?> chamado(s), <?= count($plan['access_logs'] ?? []) + count($plan['visit_forecasts'] ?? []) ?> registro(s) da portaria e <?= count($plan['moves'] ?? []) + count($plan['reservations'] ?? []) + count($plan['waitlist'] ?? []) + count($plan['collection_contacts'] ?? []) ?> de mudança, reserva, espera ou cobrança. Ficam guardadas <?= (int)($plan['kept']['receivables'] ?? 0) ?> conta(s) a receber, <?= (int)($plan['kept']['board_members'] ?? 0) ?> cargo(s) no conselho e <?= (int)($plan['kept']['assembly_attendance'] ?? 0) ?> presença(s) em assembleia (atas).</p>
        <form method="post" action="<?= $this->e($route . '/anonimizar') ?>" class="erp-form" data-erp-confirm="Anonimizar os dados dessa pessoa? Nome, documento, e-mail e telefone serão apagados do sistema." data-confirm-undo="nao" data-confirm-undo-text="Não tem volta: os dados só voltam pelo backup.">
            <?= csrf_input() ?><?= $hidden() ?>
            <div class="erp-field"><label for="privacy-reason">Motivo</label><input class="erp-input" id="privacy-reason" name="reason" required minlength="10" maxlength="500" placeholder="Ex.: pedido do titular por e-mail em 16/11/2026"></div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--danger">Anonimizar</button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
