<?php
/**
 * Regra de cobrança e índices (AP-165, ADR 0040) com dados reais: a regra que vale para o condomínio
 * da barra superior ("Todos" = padrão da Connect) e os meses do IPCA ($billingRule, $priceIndexes de
 * Panel::billingData). Gravar usa a API /api/v1/billing-rules e /api/v1/price-indexes (erp.js,
 * data-erp-billing). O padrão da Connect e os índices exigem acesso a todos os condomínios (a API
 * recusa com 403 e a tela mostra a mensagem).
 * @var array $currentPage @var array $billingRule @var list<array> $priceIndexes @var bool $canManageBilling
 */
$this->layout('default');
$condo = \Source\Support\Erp\CondominiumContext::current();
$context = \Source\Support\Erp\CondominiumContext::id();
$canManageBilling ??= false;
$rule = $billingRule;
$pct = static fn (int $bp): string => number_format($bp / 100, 2, ',', '.');
$month = static fn (string $value): string => substr($value, 5, 2) . '/' . substr($value, 0, 4);
$sources = ['condominium' => ['Regra própria do condomínio', 'success'], 'default' => ['Padrão da Connect', 'info'], 'builtin' => ['Padrão do sistema (ainda não salvo)', 'plain']];
[$sourceLabel, $sourceTone] = $sources[$rule['source'] ?? 'builtin'] ?? $sources['builtin'];
$rates = $priceIndexes ?? []; // do mais novo para o mais antigo
$latest = $rates[0]['competence'] ?? null;
$sum12 = 1.0;
foreach (array_slice($rates, 0, 12) as $r) { $sum12 *= 1 + ((float)$r['rate_percent']) / 100; }
$disabled = $canManageBilling ? '' : ' disabled';

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => 'Índices e regra de cobrança',
    'pageLead' => 'Multa, juros e correção das contas em atraso, a regra de quebra dos acordos e os índices mensais usados na correção.',
    'pageActions' => '',
]);
?>
<div class="erp-stack" data-erp-billing data-rules-api="<?= $this->e(url('/api/v1/billing-rules')) ?>" data-indexes-api="<?= $this->e(url('/api/v1/price-indexes')) ?>">
    <?= csrf_input() ?>
    <section class="erp-card" aria-labelledby="bill-rule">
        <div class="erp-card__head"><div><h2 id="bill-rule">Regra de cobrança · <?= $this->e($condo !== null ? (string)$condo['name'] : 'padrão da Connect') ?></h2><p><span class="erp-badge erp-badge--<?= $sourceTone ?>"><?= $this->e($sourceLabel) ?></span><?= $context === null ? ' Vale para os condomínios sem regra própria. Escolha um condomínio na barra superior para dar a ele uma regra própria.' : '' ?></p></div></div>
        <form data-billing-rule-form data-condominium="<?= $context !== null ? (int)$context : '' ?>">
            <div class="erp-form__grid">
                <div class="erp-field erp-col-3"><label for="bill-fine">Multa (%)</label><input class="erp-input" type="number" id="bill-fine" name="fine_pct" min="0" max="10" step="0.01" value="<?= $this->e(number_format(((int)$rule['fine_bp']) / 100, 2, '.', '')) ?>" required<?= $disabled ?>><small class="erp-muted">Uma vez, sobre o valor corrigido (até 10%)</small></div>
                <div class="erp-field erp-col-3"><label for="bill-interest">Juros (% ao mês)</label><input class="erp-input" type="number" id="bill-interest" name="interest_pct" min="0" max="10" step="0.01" value="<?= $this->e(number_format(((int)$rule['monthly_interest_bp']) / 100, 2, '.', '')) ?>" required<?= $disabled ?>><small class="erp-muted">Pro rata por dia de atraso</small></div>
                <div class="erp-field erp-col-3"><label for="bill-index">Correção</label><select class="erp-select" id="bill-index" name="price_index"<?= $disabled ?>><option value="IPCA"<?= $rule['price_index'] === 'IPCA' ? ' selected' : '' ?>>IPCA</option><option value="NONE"<?= $rule['price_index'] === 'NONE' ? ' selected' : '' ?>>Sem correção</option></select></div>
                <div class="erp-field erp-col-3"></div>
                <div class="erp-field erp-col-3"><label for="bill-breach-days">Quebra: parcela atrasada há mais de (dias)</label><input class="erp-input" type="number" id="bill-breach-days" name="breach_days_late" min="1" max="365" value="<?= (int)$rule['breach_days_late'] ?>" required<?= $disabled ?>></div>
                <div class="erp-field erp-col-3"><label for="bill-breach-open">ou parcelas vencidas em aberto</label><input class="erp-input" type="number" id="bill-breach-open" name="breach_open_installments" min="0" max="60" value="<?= (int)$rule['breach_open_installments'] ?>" required<?= $disabled ?>><small class="erp-muted">0 = não usar este critério</small></div>
            </div>
            <p class="erp-muted erp-billing-note">Hoje: multa de <?= $pct((int)$rule['fine_bp']) ?>%, juros de <?= $pct((int)$rule['monthly_interest_bp']) ?>% ao mês e correção <?= $rule['price_index'] === 'NONE' ? 'desligada' : 'pelo IPCA' ?>. A quebra do acordo nunca é automática: o ERP só indica e alguém marca.</p>
            <?php if ($canManageBilling): ?><div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar regra</button></div><?php endif; ?>
        </form>
    </section>

    <section class="erp-card" aria-labelledby="bill-ipca">
        <div class="erp-card__head"><div><h2 id="bill-ipca">IPCA mensal</h2><p><?= count($rates) ?> mês(es) lançados<?= $latest !== null ? ' · último: ' . $this->e($month($latest)) . ' · 12 meses: ' . number_format(($sum12 - 1) * 100, 2, ',', '.') . '%' : '' ?>. Mês sem índice entra como zero e aparece como aviso nos encargos.</p></div></div>
        <?php if ($canManageBilling): ?>
            <div class="erp-billing-forms">
                <form class="erp-filters" data-index-form aria-label="Lançar um mês">
                    <div class="erp-field erp-field--compact"><label for="idx-month">Competência</label><input class="erp-input" type="month" id="idx-month" name="competence" required></div>
                    <div class="erp-field erp-field--compact"><label for="idx-rate">Variação (%)</label><input class="erp-input" id="idx-rate" name="rate_percent" inputmode="decimal" placeholder="0,44" pattern="^-?\d{1,3}([.,]\d{1,6})?$" required></div>
                    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('plus', 'erp-icon--sm') ?>Lançar</button></div>
                </form>
                <form class="erp-filters" data-index-import aria-label="Importar arquivo de índices">
                    <div class="erp-field erp-field--grow"><label for="idx-file">Importar arquivo (linhas AAAA-MM;variação)</label><input class="erp-input" type="file" id="idx-file" name="file" accept=".txt,.csv" required></div>
                    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('upload', 'erp-icon--sm') ?>Importar</button></div>
                </form>
            </div>
        <?php endif; ?>
        <?php if ($rates === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nenhum mês do IPCA lançado</strong><p>Sem índice, a correção fica em zero. Lance os meses ou importe um arquivo.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap erp-billing-rates" role="region" aria-labelledby="bill-ipca" tabindex="0"><table class="erp-table">
                <thead><tr><th>Competência</th><th class="is-num">Variação</th></tr></thead>
                <tbody><?php foreach ($rates as $r): ?><tr><td><?= $this->e($month((string)$r['competence'])) ?></td><td class="is-num"><?= number_format((float)$r['rate_percent'], 2, ',', '.') ?>%</td></tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
