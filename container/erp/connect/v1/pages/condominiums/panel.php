<?php
/**
 * Painel do condomínio (AP-002, ADR 0054 e 0071) com dados reais: ocupação, financeiro e operacional
 * do condomínio da barra superior ($condoPanel de Panel::condominiumPanelData, mesmo serviço de
 * GET /api/v1/panels/condominium/{id}). Em "Todos os condomínios", pede a escolha de um.
 * @var array $currentPage @var ?array $condoPanel @var ?string $condoPanelError
 * @var list<array{id:int,name:string,slug:string}> $condoOptions
 */
$this->layout('default');
$brl = static fn (int $cents): string => ($cents < 0 ? '−' : '') . 'R$ ' . number_format(abs($cents) / 100, 2, ',', '.');
$day = static fn (?string $date): string => $date ? date('d/m/Y', (int)strtotime($date)) : '—';
$pct = static fn (float $v): string => number_format($v, 1, ',', '.') . '%';
$closingLabels = ['open' => 'Aberta', 'closed' => 'Fechada', 'reopened' => 'Reaberta'];
$bucketLabels = ['1-30' => 'Até 30 dias', '31-60' => '31 a 60 dias', '61-90' => '61 a 90 dias', '90+' => 'Acima de 90 dias'];
$alertTone = ['danger' => 'danger', 'warning' => 'warning', 'info' => ''];
$panel = $condoPanel ?? null;

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $panel !== null ? $panel['condominium']['name'] : $currentPage['name'],
    'pageLead' => $panel !== null ? 'Ocupação, financeiro e rotina do condomínio em ' . $day($panel['as_of']) . '. Os números vêm dos cadastros e lançamentos.' : 'Ocupação, financeiro e rotina de um condomínio em uma tela.',
    'pageActions' => $panel !== null ? '<a class="erp-btn" href="' . erp_url('/condominios/' . (int)$panel['condominium']['id']) . '">' . $icon('building', 'erp-icon--sm') . 'Ficha do condomínio</a>' : '',
]);
?>
<?php if (($condoPanelError ?? null) !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($condoPanelError) ?></div></div>
<?php endif; ?>

<?php if ($panel === null): ?>
    <?php if (($condoPanelError ?? null) === null): ?>
        <section class="erp-card" aria-labelledby="panel-pick-title">
            <div class="erp-card__head"><div><h2 id="panel-pick-title">Escolha um condomínio</h2><p>O painel mostra um condomínio por vez. Escolha abaixo ou na barra superior.</p></div></div>
            <?php if (($condoOptions ?? []) === []): ?>
                <p class="erp-field__hint">Você ainda não tem acesso a nenhum condomínio ativo.</p>
            <?php else: ?>
                <ul class="erp-summary">
                    <?php foreach ($condoOptions as $option): ?>
                        <li><a href="<?= $this->e(erp_context_url($currentPage['route'], $option)) ?>"><span class="erp-summary__icon"><?= $icon('building', 'erp-icon--sm') ?></span><small><?= $this->e((string)$option['name']) ?></small></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php else:
    $occ = $panel['occupancy'];
    $fin = $panel['finance'];
    $ops = $panel['operations'];
    $kpis = [
        ['label' => 'Unidades', 'value' => (string)$occ['units'], 'meta' => $occ['occupied'] . ' ocupadas · ' . $occ['vacant'] . ' vagas (' . $pct((float)$occ['rate']) . ')', 'icon' => 'building', 'tone' => 'hero', 'trend' => null],
        ['label' => 'Saldo em bancos', 'value' => $brl((int)$fin['bank_balance_cents']), 'meta' => count($fin['bank_accounts']) . ' conta(s) bancária(s)', 'icon' => 'wallet', 'tone' => (int)$fin['bank_balance_cents'] < 0 ? 'danger' : '', 'trend' => null],
        ['label' => 'Inadimplência', 'value' => $brl((int)$fin['delinquency_cents']), 'meta' => $fin['delinquent_units'] . ' unidade(s) · encargos ' . $brl((int)$fin['delinquency_charges_cents']), 'icon' => 'alert', 'tone' => (int)$fin['delinquency_cents'] > 0 ? 'danger' : '', 'trend' => null],
        ['label' => 'Resultado de ' . substr((string)$fin['competence'], 5, 2) . '/' . substr((string)$fin['competence'], 0, 4), 'value' => $brl((int)$fin['competence_result_cents']), 'meta' => 'Competência ' . ($closingLabels[$fin['closing_status']] ?? (string)$fin['closing_status']), 'icon' => 'chart', 'tone' => (int)$fin['competence_result_cents'] < 0 ? 'warning' : '', 'trend' => null],
    ];
    $bucketTotal = max(1, array_sum(array_map('intval', $fin['buckets'])));
?>
    <section class="erp-grid erp-grid--kpi" aria-label="Indicadores do condomínio" data-erp-condo-panel>
        <?php foreach ($kpis as $i => $kpi) { $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 60]); } ?>
    </section>

    <div class="erp-grid erp-grid--2">
        <section class="erp-card" aria-labelledby="panel-alerts-title">
            <div class="erp-card__head"><div><h2 id="panel-alerts-title">Alertas</h2><p>Rotina do condomínio</p></div></div>
            <?php if (($ops['alerts'] ?? []) === []): ?>
                <p class="erp-field__hint">Nada pendente: manutenções, seguros, contratos, reservas, ocorrências e entregas em dia.</p>
            <?php else: ?>
                <ul class="erp-list erp-dash-alerts">
                    <?php foreach ($ops['alerts'] as $alert): $tone = $alertTone[$alert['severity']] ?? ''; ?>
                        <li><span class="erp-list__icon<?= $tone !== '' ? ' erp-list__icon--' . $tone : '' ?>"><?= $icon($tone === '' ? 'info' : 'alert', 'erp-icon--sm') ?></span>
                            <div class="erp-list__body"><a href="<?= erp_url((string)$alert['route']) ?>"><strong><?= $this->e((string)$alert['message']) ?></strong></a></div>
                            <span class="erp-badge erp-badge--<?= $tone !== '' ? $tone : 'info' ?>"><?= (int)$alert['count'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="erp-card" aria-labelledby="panel-debt-title">
            <div class="erp-card__head"><div><h2 id="panel-debt-title">Inadimplência por atraso</h2><p>Valor em aberto por faixa, sem encargos</p></div><a class="erp-btn erp-btn--sm" href="<?= erp_url('/cobranca/acordos') ?>">Inadimplência e acordos</a></div>
            <?php if ((int)$fin['delinquency_cents'] === 0): ?>
                <p class="erp-field__hint">Nenhuma unidade em atraso.</p>
            <?php else: ?>
                <ul class="erp-panel-bars">
                    <?php foreach ($bucketLabels as $key => $label): $value = (int)($fin['buckets'][$key] ?? 0); ?>
                        <li><span><?= $this->e($label) ?></span><div class="erp-progress" aria-hidden="true"><span style="width: <?= round($value / $bucketTotal * 100, 1) ?>%"></span></div><strong><?= $this->e($brl($value)) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="erp-grid erp-grid--2">
        <section class="erp-card" aria-labelledby="panel-banks-title">
            <div class="erp-card__head"><div><h2 id="panel-banks-title">Contas bancárias</h2><p>Saldo de abertura mais recebimentos e menos pagamentos baixados</p></div></div>
            <?php if ($fin['bank_accounts'] === []): ?>
                <p class="erp-field__hint">Nenhuma conta bancária cadastrada. <a href="<?= erp_url('/financeiro/contas-bancarias') ?>">Cadastrar conta</a></p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($fin['bank_accounts'] as $account): ?>
                        <li><span class="erp-list__icon"><?= $icon('wallet', 'erp-icon--sm') ?></span>
                            <div class="erp-list__body"><strong><?= $this->e((string)$account['name']) ?><?= !empty($account['is_main']) ? ' <span class="erp-badge erp-badge--plain">Principal</span>' : '' ?></strong><small>Recebido <?= $this->e($brl((int)$account['received_cents'])) ?> · pago <?= $this->e($brl((int)$account['paid_cents'])) ?></small></div>
                            <span class="erp-list__meta erp-nowrap<?= (int)$account['balance_cents'] < 0 ? ' erp-panel-negative' : '' ?>"><?= $this->e($brl((int)$account['balance_cents'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="erp-card" aria-labelledby="panel-ops-title">
            <div class="erp-card__head"><div><h2 id="panel-ops-title">Rotina</h2><p>Contagens de hoje</p></div></div>
            <?php $c = $ops['cards'] ?? []; $routine = [
                ['Manutenções nos próximos 7 dias', (int)($c['maintenances_week'] ?? 0), '/operacional/manutencoes'],
                ['Reservas aguardando aprovação', (int)($c['reservations_pending'] ?? 0), '/operacional/agendamentos-pendentes'],
                ['Ocorrências abertas', (int)($c['occurrences_open'] ?? 0), '/operacional/ocorrencias'],
                ['Entregas aguardando retirada', (int)($c['deliveries_waiting'] ?? 0), '/operacional/entregas'],
                ['A receber nos próximos 7 dias', $brl((int)($c['receivables_week_cents'] ?? 0)), '/financeiro/contas-a-receber'],
                ['A pagar nos próximos 7 dias', $brl((int)($c['payables_week_cents'] ?? 0)), '/financeiro/contas-a-pagar'],
            ]; ?>
            <ul class="erp-list">
                <?php foreach ($routine as [$label, $value, $route]): ?>
                    <li><div class="erp-list__body"><a href="<?= erp_url($route) ?>"><?= $this->e($label) ?></a></div><span class="erp-list__meta erp-nowrap"><strong><?= $this->e((string)$value) ?></strong></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
