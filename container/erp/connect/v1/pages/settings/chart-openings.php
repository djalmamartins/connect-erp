<?php
/**
 * Saldo inicial do plano de contas (AP-161; ADR 0146, sobre o ADR 0166) com dados reais do condomínio da barra
 * superior. Só a conta analítica recebe saldo; a sintética mostra a soma das analíticas abaixo dela. Gravar usa
 * POST /api/v1/chart-account-openings (erp.js, data-erp-settings); gravar de novo a mesma conta substitui.
 * @var array $currentPage @var ?int $settingsContext @var bool $settingsNeedsCondo @var ?array $chartOpenings @var list<array> $openingAccounts @var bool $canManageSettings
 */
use Source\Controllers\Erp\Connect\ReportScreens;

$this->layout('default');
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condo['name'] ?? 'o condomínio');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Saldo de partida de cada conta analítica do plano de contas numa data. A conta sintética mostra a soma das analíticas abaixo dela. Valor negativo = saldo devedor.',
    'pageActions' => '',
]);
?>
<?php if ($settingsNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>O saldo inicial é de cada condomínio. Escolha o condomínio na barra superior.</p></div>
<?php else: $t = $chartOpenings['totals']; ?>
<div class="erp-stack" data-erp-settings data-chart-openings-api="<?= $this->e(url('/api/v1/chart-account-openings')) ?>" data-condominium="<?= (int)$settingsContext ?>">
    <?= csrf_input() ?>
    <?php if ($canManageSettings): ?>
        <section class="erp-card" aria-labelledby="co-form-title">
            <div class="erp-card__head"><div><h2 id="co-form-title">Lançar saldo inicial</h2><p><?= $this->e($condoName) ?> · gravar de novo a mesma conta substitui o saldo anterior.</p></div></div>
            <?php if ($openingAccounts === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('clipboard') ?></span><strong>Nenhuma conta analítica ativa</strong><p>Cadastre as contas em <a href="<?= $this->e(erp_url($catalog['pages']['AP-160']['route'] ?? '/configuracoes/plano-de-contas')) ?>">Plano de contas</a>.</p></div>
            <?php else: ?>
                <form class="erp-form" data-chart-opening-form aria-labelledby="co-form-title" novalidate>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-6"><label for="co-account">Conta analítica</label><select class="erp-select" id="co-account" name="chart_account_id" required><option value="">Escolha</option><?php foreach ($openingAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-3"><label for="co-date">Data do saldo</label><input class="erp-input" type="date" id="co-date" name="opened_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-01-01') ?>" required></div>
                        <div class="erp-field erp-col-3"><label for="co-amount">Valor (R$)</label><input class="erp-input" type="text" id="co-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^-?\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required aria-describedby="co-amount-hint"><small class="erp-muted" id="co-amount-hint">Use o sinal de menos para saldo devedor</small></div>
                        <div class="erp-field erp-col-12"><label for="co-notes">Observação</label><input class="erp-input" type="text" id="co-notes" name="notes" maxlength="255"></div>
                    </div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar saldo inicial</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="co-title">
        <div class="erp-card__head"><div><h2 id="co-title">Saldos iniciais</h2><p><?= $this->e($condoName) ?> · receitas <?= $this->e(ReportScreens::brl((int)$t['revenue_cents'])) ?> · despesas <?= $this->e(ReportScreens::brl((int)$t['expense_cents'])) ?> · total do resultado <?= $this->e(ReportScreens::brl((int)$t['total_cents'])) ?><?php foreach (['asset_cents' => 'ativo', 'liability_cents' => 'passivo', 'equity_cents' => 'patrimônio líquido'] as $k => $l): if (!empty($t[$k])): ?> · <?= $l ?> <?= $this->e(ReportScreens::brl((int)$t[$k])) ?><?php endif; endforeach; ?></p></div></div>
        <?php if ($chartOpenings['rows'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhum saldo inicial</strong><p>Sem saldo inicial, as contas do plano começam do zero.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="co-title" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Conta</th><th scope="col">Natureza</th><th scope="col">Data</th><th scope="col" class="is-num">Valor</th><th scope="col">Observação</th></tr></thead>
                <tbody><?php foreach ($chartOpenings['rows'] as $r): ?><tr<?= $r['analytic'] ? '' : ' class="erp-report-synthetic"' ?>>
                    <td class="erp-nowrap"><?= $this->e($r['code']) ?></td>
                    <td><span class="erp-report-account" style="--erp-level: <?= max(0, (int)$r['level'] - 1) ?>"><?= $this->e($r['name']) ?></span></td>
                    <td><?= $this->e(ucfirst(\Source\Controllers\Erp\Connect\SettingsScreens::NATURE_LABELS[$r['nature']] ?? $r['nature'])) ?></td>
                    <td class="erp-nowrap"><?= $r['opened_on'] !== null ? $this->e(ReportScreens::brDate($r['opened_on'])) : '<span class="erp-muted">soma</span>' ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(ReportScreens::brl((int)$r['amount_cents'])) ?></td>
                    <td><?= $r['notes'] !== null ? $this->e((string)$r['notes']) : '<span class="erp-muted">—</span>' ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
