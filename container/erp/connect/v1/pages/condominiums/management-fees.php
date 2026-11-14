<?php
/**
 * Honorários (CN-008, ADR 0402): contrato de administração da Connect com cada condomínio do escopo, total
 * mensal dos contratos ativos e, na competência escolhida, o pedido da nota fiscal (NFS-e). ?condominio={id}
 * abre o contrato para editar e o histórico de pedidos. Gravar, pedir e cancelar são POST com CSRF
 * (ManagementFeeScreens). Sem a chave nfse.enabled ligada, o botão "Solicitar nota" não aparece.
 * @var array $currentPage @var ?array $fees @var string $feesCompetence @var ?array $feesEditing
 * @var list<array> $feesRequests @var ?string $feesError @var bool $canManage
 */
use Source\Services\Erp\ManagementFees;

$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$day = static fn (?string $v): string => $v ? date('d/m/Y', (int)strtotime($v)) : '—';
$tones = ['active' => 'success', 'suspended' => 'warning', 'ended' => 'plain', 'requested' => 'info', 'issued' => 'success', 'failed' => 'danger', 'cancelled' => 'plain'];
$enabled = (bool)($fees['nfse_enabled'] ?? false);
$rows = $fees['rows'] ?? [];

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'O valor mensal que cada condomínio paga à Connect pela administração. Daqui você acompanha o total do mês e pede a nota fiscal de cada condomínio.',
    'pageActions' => '',
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>
<?php if ($feesError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($feesError) ?></div></div>
<?php endif; ?>

<?php if ($fees === null): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Honorários indisponíveis</strong><p>Sem conexão com o banco de dados, os contratos não podem ser lidos.</p></div></section>
<?php else: ?>
<div class="erp-alert<?= $enabled ? ' erp-alert--success' : '' ?>" role="note" data-nfse-enabled="<?= $enabled ? '1' : '0' ?>"><?= $icon($enabled ? 'check' : 'info') ?><div><strong>Notas fiscais <?= $enabled ? 'ligadas' : 'desligadas' ?>.</strong> <?= $enabled
    ? 'Os pedidos de nota ficam registrados. O envio à Receita (API Nacional da NFS-e) entra na próxima etapa.'
    : 'Nenhuma nota é pedida nem emitida. Para ligar, vá em <a href="' . $this->e(erp_url('/configuracoes/financeiro')) . '">Configurações › Financeiro</a>.' ?></div></div>

<div class="erp-grid erp-grid--3" style="margin-top:16px">
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Total mensal dos contratos ativos', 'value' => $brl((int)$fees['monthly_total_cents']), 'meta' => 'Soma dos honorários', 'icon' => 'wallet', 'tone' => '', 'trend' => null]]); ?>
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Contratos ativos', 'value' => (string)(int)$fees['active_contracts'], 'meta' => count($rows) . ' condomínio(s) no seu escopo', 'icon' => 'building', 'tone' => '', 'trend' => null], 'delay' => 60]); ?>
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Notas pedidas na competência', 'value' => (string)count(array_filter($rows, static fn (array $r): bool => $r['request'] !== null)), 'meta' => 'Competência ' . $feesCompetence, 'icon' => 'receipt', 'tone' => 'info', 'trend' => null], 'delay' => 120]); ?>
</div>

<div class="erp-grid<?= $feesEditing !== null ? ' erp-grid--main' : '' ?>" style="margin-top:16px">
    <section class="erp-card" aria-labelledby="fees-title">
        <div class="erp-card__head">
            <div><h2 id="fees-title">Contratos</h2><p>Valor, situação e nota da competência de cada condomínio.</p></div>
            <form method="get" action="<?= $this->e($route) ?>" class="erp-filters">
                <div class="erp-field erp-field--compact"><label for="fees-month">Competência</label><input class="erp-input" type="month" id="fees-month" name="competencia" value="<?= $this->e($feesCompetence) ?>" min="2000-01" required></div>
                <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Ver</button></div>
            </form>
        </div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhum condomínio no seu escopo</strong><p>Os condomínios que você administra aparecem aqui.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="fees-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Condomínio</th><th scope="col" class="is-num">Valor mensal</th><th scope="col">Situação</th><th scope="col">Nota de <?= $this->e($feesCompetence) ?></th><th scope="col" class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $r): $c = $r['contract']; $id = (int)$r['condominium_id']; ?><tr<?= ($feesEditing['condominium_id'] ?? null) === $id ? ' class="is-selected"' : '' ?>>
                    <td><strong><?= $this->e($r['condominium_name']) ?></strong><?php if ($c !== null): ?><br><span class="erp-muted">desde <?= $this->e($day($c['start_date'])) ?> · vence dia <?= (int)$c['due_day'] ?></span><?php endif; ?></td>
                    <td class="is-num erp-nowrap"><?= $c !== null ? $this->e($brl($c['monthly_fee_cents'])) : '—' ?></td>
                    <td><?php if ($c === null): ?><span class="erp-muted">Sem contrato</span><?php else: ?><span class="erp-badge erp-badge--<?= $tones[$c['status']] ?>"><?= $this->e(ManagementFees::STATUSES[$c['status']]) ?></span><?php endif; ?></td>
                    <td><?php if ($r['request'] !== null): ?><span class="erp-badge erp-badge--<?= $tones[$r['request']['status']] ?? 'plain' ?>"><?= $this->e(ManagementFees::REQUEST_STATUSES[$r['request']['status']] ?? $r['request']['status']) ?></span><?php elseif ($c !== null && !$r['billable']): ?><span class="erp-muted">Fora do contrato</span><?php else: ?><span class="erp-muted">Não pedida</span><?php endif; ?></td>
                    <td class="is-num"><div class="erp-table__actions">
                        <a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?' . http_build_query(['competencia' => $feesCompetence, 'condominio' => $id])) ?>#contrato" aria-label="<?= $c === null ? 'Cadastrar' : 'Abrir' ?> os honorários de <?= $this->e($r['condominium_name']) ?>"><?= $c === null ? 'Cadastrar' : 'Abrir' ?></a>
                        <?php if ($canManage && $enabled && $c !== null && $c['status'] === 'active' && $r['billable'] && $r['request'] === null): ?>
                            <form method="post" action="<?= $this->e($route . '/' . $id . '/nota') ?>" data-erp-confirm="Solicitar a nota de <?= $this->e($feesCompetence) ?> de <?= $this->e($r['condominium_name']) ?> no valor de <?= $this->e($brl($c['monthly_fee_cents'])) ?>?"><?= csrf_input() ?><input type="hidden" name="competence" value="<?= $this->e($feesCompetence) ?>"><button type="submit" class="erp-btn erp-btn--sm erp-btn--primary">Solicitar nota</button></form>
                        <?php endif; ?>
                    </div></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($feesEditing !== null): $e = $feesEditing; $c = $e['contract']; $cid = (int)$e['condominium_id']; ?>
        <aside class="erp-card" id="contrato" tabindex="-1" aria-labelledby="fees-edit-title">
            <div class="erp-card__head"><div><h2 id="fees-edit-title"><?= $this->e($e['condominium_name']) ?></h2><p><?= $c === null ? 'Ainda sem contrato de honorários.' : 'Atualizado em ' . $this->e($day($c['updated_at'])) . '.' ?></p></div></div>
            <?php if ($canManage): ?>
            <form method="post" action="<?= $this->e($route . '/' . $cid) ?>" class="erp-form">
                <?= csrf_input() ?>
                <div class="erp-form__grid">
                    <div class="erp-field"><label for="fee-value">Valor mensal (R$)</label><input class="erp-input" id="fee-value" name="monthly_fee" inputmode="decimal" required placeholder="1.500,00" value="<?= $c !== null ? $this->e(number_format($c['monthly_fee_cents'] / 100, 2, ',', '.')) : '' ?>"></div>
                    <div class="erp-field"><label for="fee-due">Dia de vencimento</label><input class="erp-input" type="number" id="fee-due" name="due_day" min="1" max="28" required value="<?= $c !== null ? (int)$c['due_day'] : 10 ?>"></div>
                    <div class="erp-field"><label for="fee-start">Início do contrato</label><input class="erp-input" type="date" id="fee-start" name="start_date" required value="<?= $this->e($c['start_date'] ?? '') ?>"></div>
                    <div class="erp-field"><label for="fee-end">Fim (opcional)</label><input class="erp-input" type="date" id="fee-end" name="end_date" value="<?= $this->e($c['end_date'] ?? '') ?>"></div>
                    <div class="erp-field"><label for="fee-status">Situação</label><select class="erp-select" id="fee-status" name="status"><?php foreach (ManagementFees::STATUSES as $key => $label): ?><option value="<?= $key ?>"<?= ($c['status'] ?? 'active') === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="erp-field"><label for="fee-service">Descrição do serviço na nota</label><input class="erp-input" id="fee-service" name="service_description" maxlength="255" value="<?= $this->e($c['service_description'] ?? ManagementFees::DEFAULT_SERVICE) ?>"></div>
                </div>
                <div class="erp-field"><label for="fee-notes">Observações</label><textarea class="erp-textarea" id="fee-notes" name="notes" maxlength="500" rows="2"><?= $this->e($c['notes'] ?? '') ?></textarea></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Salvar honorários</button></div>
            </form>
            <?php elseif ($c !== null): ?>
                <p><?= $this->e($brl($c['monthly_fee_cents'])) ?> por mês, vence dia <?= (int)$c['due_day'] ?>. <?= $this->e($c['service_description']) ?></p>
            <?php endif; ?>

            <h3 id="pedidos" style="margin-top:16px">Pedidos de nota</h3>
            <?php if ($feesRequests === []): ?>
                <p class="erp-muted">Nenhum pedido de nota ainda.</p>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="pedidos" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Competência</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($feesRequests as $q): ?><tr>
                        <td><?= $this->e($q['competence']) ?><br><span class="erp-muted">pedida em <?= $this->e($day($q['requested_at'])) ?></span></td>
                        <td class="is-num erp-nowrap"><?= $this->e($brl($q['amount_cents'])) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tones[$q['status']] ?? 'plain' ?>"><?= $this->e(ManagementFees::REQUEST_STATUSES[$q['status']] ?? $q['status']) ?></span><?php if ($q['cancel_reason']): ?><br><span class="erp-muted"><?= $this->e((string)$q['cancel_reason']) ?></span><?php endif; ?></td>
                        <td class="is-num"><?php if ($canManage && in_array($q['status'], ['requested', 'failed'], true)): ?>
                            <form method="post" action="<?= $this->e($route . '/pedidos/' . (int)$q['id'] . '/cancelar') ?>" class="erp-table__actions" data-erp-confirm="Cancelar o pedido de nota de <?= $this->e($q['competence']) ?>?"><?= csrf_input() ?><input type="hidden" name="back" value="<?= $cid ?>"><label class="erp-sr-only" for="reason-<?= (int)$q['id'] ?>">Motivo</label><input class="erp-input" id="reason-<?= (int)$q['id'] ?>" name="reason" required minlength="3" maxlength="255" placeholder="Motivo"><button type="submit" class="erp-btn erp-btn--sm">Cancelar</button></form>
                        <?php endif; ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
