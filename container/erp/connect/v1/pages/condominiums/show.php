<?php
/**
 * Visão geral do condomínio (ADR 0151 e 0154): a única tela do condomínio. O menu Condomínios abre
 * esta tela direto quando há um condomínio na barra superior. Em cima, os passos do cadastro
 * (CondominiumSetup); depois, os indicadores do antigo "Painel do condomínio" (AP-002, fundido
 * aqui: PanelSummaries, o mesmo serviço de GET /api/v1/panels/condominium/{id}); no centro, a
 * identificação e a situação; na lateral, os cadastros do condomínio e a rotina. Somente leitura.
 *
 * @var array<string, mixed> $condominium  colunas de CondominiumValidator::FIELDS + id
 * @var array{condominium:array,steps:list<array>,current:null} $setupSteps
 * @var ?array $condoPanel        PanelSummaries::condominium (null sem banco ou sem erp.units.view)
 * @var ?string $condoPanelError
 */
$this->layout('default');
$c = $condominium;
$id = (int)$c['id'];
$active = ($c['status'] ?? 'active') === 'active';
$panel = $condoPanel ?? null;
$panelError = $condoPanelError ?? null;
$text = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (string)$value;
$brl = static fn (int $cents): string => ($cents < 0 ? '−' : '') . 'R$ ' . number_format(abs($cents) / 100, 2, ',', '.');
$day = static fn (?string $date): string => $date ? date('d/m/Y', (int)strtotime($date)) : '—';
$pct = static fn (float $v): string => number_format($v, 1, ',', '.') . '%';
$zip = preg_replace('/\D+/', '', (string)($c['zip_code'] ?? ''));
$address = trim(implode(', ', array_filter([
    trim(($c['street'] ?? '') . (($c['number'] ?? '') !== '' ? ', ' . $c['number'] : '')),
    $c['complement'] ?? '',
    $c['district'] ?? '',
    trim(($c['city'] ?? '') . (($c['state'] ?? '') !== '' ? '/' . $c['state'] : '')),
], static fn ($part): bool => trim((string)$part) !== '')));
$contract = $c['contract_start_date'] ?? null;
$phoneDigits = preg_replace('/\D+/', '', (string)($c['phone'] ?? ''));
$phone = match (strlen($phoneDigits)) {
    11 => sprintf('(%s) %s-%s', substr($phoneDigits, 0, 2), substr($phoneDigits, 2, 5), substr($phoneDigits, 7)),
    10 => sprintf('(%s) %s-%s', substr($phoneDigits, 0, 2), substr($phoneDigits, 2, 4), substr($phoneDigits, 6)),
    default => $text($c['phone'] ?? null),
};
ob_start(); ?>
    <a class="erp-btn" href="<?= url(erp_base() . '/' . \Source\Support\Erp\CondominiumContext::ALL_SEGMENT . '/condominios') ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Todos os condomínios</a>
    <a class="erp-btn" href="<?= erp_url('/condominios/' . $id . '/checklist') ?>"><?= $icon('clipboard', 'erp-icon--sm') ?>Checklist de chegada</a>
    <?php if ($user->can('erp.condominiums.manage')): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url('/condominios/' . $id . '/editar') ?>"><?= $icon('edit', 'erp-icon--sm') ?>Editar</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínio',
    'pageTitle' => (string)$c['name'],
    'pageLead' => $panel !== null
        ? 'Visão geral em ' . $day($panel['as_of']) . ': cadastro, situação do condomínio e atalhos. Os números vêm dos cadastros e lançamentos.'
        : 'Visão geral do condomínio: cadastro, o que já foi feito e atalhos para os cadastros dele.',
    'pageActions' => $actions,
]);
// Cadastros ligados ao condomínio: cada link abre a lista real já filtrada por ele.
$related = [];
foreach (\Source\Services\Erp\Registry\Definitions::all() as $definition) {
    if ($definition['scope'] === 'none' || !$user->can($definition['permission'] . '.view')) {
        continue;
    }
    $related[] = $definition;
}
$closingLabels = ['open' => 'Aberta', 'closed' => 'Fechada', 'reopened' => 'Reaberta'];
$bucketLabels = ['1-30' => 'Até 30 dias', '31-60' => '31 a 60 dias', '61-90' => '61 a 90 dias', '90+' => 'Acima de 90 dias'];
$alertTone = ['danger' => 'danger', 'warning' => 'warning', 'info' => ''];
?>
<?php if (($setupSteps['steps'] ?? []) !== []) { $this->insert('components/layout/setup-guide', ['guide' => $setupSteps, 'inline' => true]); } ?>

<?php if ($panelError !== null): ?>
    <div class="erp-alert erp-alert--warning" role="status"><?= $icon('alert') ?><div><?= $this->e($panelError) ?></div></div>
<?php endif; ?>

<?php if ($panel !== null):
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
<?php endif; ?>

<div class="erp-grid erp-grid--main">
    <div class="erp-stack">
        <section class="erp-card" aria-labelledby="condo-data-title">
            <div class="erp-card__head"><div><h2 id="condo-data-title">Identificação</h2><p>Cadastro do condomínio (AP-004)</p></div>
                <span class="erp-badge erp-badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Ativo' : 'Inativo' ?></span></div>
            <div class="erp-condo-blocks">
                <div>
                    <dl class="erp-detail-grid">
                        <div><dt>CNPJ</dt><dd><?= $this->e(format_cnpj($c['document'] ?? null)) ?></dd></div>
                        <div><dt>Nome</dt><dd><?= $this->e($text($c['name'])) ?></dd></div>
                        <div><dt>Razão social</dt><dd><?= $this->e($text($c['legal_name'] ?? null)) ?></dd></div>
                        <div><dt>Código interno</dt><dd><?= $id ?></dd></div>
                    </dl>
                </div>
                <div>
                    <h3 id="condo-address-title">Endereço e contato</h3>
                    <dl class="erp-detail-grid">
                        <div><dt>Endereço</dt><dd><?= $this->e($text($address)) ?></dd></div>
                        <div><dt>CEP</dt><dd><?= $this->e(strlen($zip) === 8 ? substr($zip, 0, 5) . '-' . substr($zip, 5) : $text($c['zip_code'] ?? null)) ?></dd></div>
                        <div><dt>Telefone</dt><dd><?= $this->e($phone) ?></dd></div>
                        <div><dt>E-mail</dt><dd><?= $this->e($text($c['email'] ?? null)) ?></dd></div>
                    </dl>
                </div>
                <div>
                    <h3 id="condo-contract-title">Estrutura, contrato e banco</h3>
                    <dl class="erp-detail-grid">
                        <div><dt>Blocos</dt><dd><?= $this->e($text($c['blocks_count'] ?? null)) ?></dd></div>
                        <div><dt>Unidades (informadas no cadastro)</dt><dd><?= $this->e($text($c['units_count'] ?? null)) ?></dd></div>
                        <div><dt>Início do contrato</dt><dd><?= $this->e($contract ? date('d/m/Y', (int)strtotime((string)$contract)) : '—') ?></dd></div>
                        <div><dt>Vencimento da taxa</dt><dd><?= $this->e(($c['fee_due_day'] ?? null) ? 'Dia ' . (int)$c['fee_due_day'] : '—') ?></dd></div>
                        <div><dt>Banco</dt><dd><?= $this->e($text($c['bank_code'] ?? null)) ?></dd></div>
                        <div><dt>Agência e conta</dt><dd><?= $this->e(($c['bank_branch'] ?? '') !== '' || ($c['bank_account'] ?? '') !== '' ? trim(($c['bank_branch'] ?? '') . ' / ' . ($c['bank_account'] ?? ''), ' /') : '—') ?></dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <?php if ($panel !== null): ?>
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
        <?php endif; ?>
    </div>

    <div class="erp-stack">
        <?php if ($panel !== null): $counts = $ops['cards'] ?? []; $routine = [
            ['Manutenções nos próximos 7 dias', (int)($counts['maintenances_week'] ?? 0), '/operacional/manutencoes'],
            ['Reservas aguardando aprovação', (int)($counts['reservations_pending'] ?? 0), '/operacional/agendamentos-pendentes'],
            ['Ocorrências abertas', (int)($counts['occurrences_open'] ?? 0), '/operacional/ocorrencias'],
            ['Entregas aguardando retirada', (int)($counts['deliveries_waiting'] ?? 0), '/operacional/entregas'],
            ['A receber nos próximos 7 dias', $brl((int)($counts['receivables_week_cents'] ?? 0)), '/financeiro/contas-a-receber'],
            ['A pagar nos próximos 7 dias', $brl((int)($counts['payables_week_cents'] ?? 0)), '/financeiro/contas-a-pagar'],
        ]; ?>
            <section class="erp-card" aria-labelledby="panel-ops-title">
                <div class="erp-card__head"><div><h2 id="panel-ops-title">Rotina</h2><p>Contagens de hoje</p></div></div>
                <ul class="erp-list">
                    <?php foreach ($routine as [$label, $value, $route]): ?>
                        <li><div class="erp-list__body"><a href="<?= erp_url($route) ?>"><?= $this->e($label) ?></a></div><span class="erp-list__meta erp-nowrap"><strong><?= $this->e((string)$value) ?></strong></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <aside class="erp-card" aria-labelledby="condo-related-title">
            <div class="erp-card__head"><div><h2 id="condo-related-title">Cadastros do condomínio</h2><p>Abre a lista já filtrada por este condomínio</p></div></div>
            <?php if ($related === []): ?>
                <p class="erp-field__hint">Você não tem acesso a nenhum cadastro deste condomínio.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($related as $definition): ?>
                        <li><span class="erp-list__icon"><?= $icon('clipboard', 'erp-icon--sm') ?></span>
                            <div class="erp-list__body"><a href="<?= erp_url($definition['route']) ?>?condominio=<?= $id ?>"><strong><?= $this->e($definition['label']) ?></strong></a></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </aside>
    </div>
</div>
