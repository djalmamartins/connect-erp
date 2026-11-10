<?php
/**
 * Previsão orçamentária (AP-163; ADR 0144, sobre o ADR 0085) com dados reais do condomínio da barra superior:
 * o previsto de cada conta analítica mês a mês, com o lançado (competência) e a diferença. Gravar usa
 * POST /api/v1/budgets (erp.js, data-erp-settings) e substitui os 12 meses da conta no ano; competência
 * fechada não muda (a API recusa e a tela mostra a mensagem). Sem condomínio, a tela pede um.
 * @var array $currentPage @var ?int $settingsContext @var bool $settingsNeedsCondo @var ?array $budget @var int $budgetYear
 * @var list<array> $budgetAccounts @var bool $canManageSettings
 */
use Source\Controllers\Erp\Connect\ReportScreens;

$this->layout('default');
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condoName ?? 'o condomínio');
$months = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$plain = static fn (int $cents): string => $cents === 0 ? '' : number_format($cents / 100, 2, ',', '');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Quanto o condomínio prevê receber e gastar em cada conta, mês a mês. O relatório de previsão orçamentária compara com o que foi lançado.',
    'pageActions' => '',
]);
?>
<?php if ($settingsNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>A previsão orçamentária é de cada condomínio. Escolha o condomínio na barra superior.</p></div>
<?php else: $rows = $budget['rows']; ?>
<form class="erp-filters" method="get" action="<?= $this->e(erp_url($currentPage['route'])) ?>" aria-label="Ano da previsão">
    <div class="erp-field erp-field--compact"><label for="bud-year">Ano</label><input class="erp-input" type="number" id="bud-year" name="ano" min="2000" max="<?= (int)date('Y') + 5 ?>" value="<?= (int)$budgetYear ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Ver</button></div>
</form>
<div class="erp-stack" data-erp-settings data-budget-api="<?= $this->e(url('/api/v1/budgets')) ?>" data-condominium="<?= (int)$settingsContext ?>" data-year="<?= (int)$budgetYear ?>">
    <?= csrf_input() ?>
    <?php if ($canManageSettings): ?>
        <section class="erp-card" aria-labelledby="bud-form-title">
            <div class="erp-card__head"><div><h2 id="bud-form-title">Previsto de uma conta em <?= (int)$budgetYear ?></h2><p>Os 12 meses da conta são gravados juntos; mês vazio = zero.</p></div></div>
            <?php if ($budgetAccounts === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('clipboard') ?></span><strong>Nenhuma conta analítica ativa</strong><p>Cadastre as contas no plano de contas.</p></div>
            <?php else: ?>
                <form class="erp-form" data-budget-form aria-labelledby="bud-form-title">
                    <div data-form-error></div>
                    <div class="erp-form__grid"><div class="erp-field erp-col-6"><label for="bud-account">Conta do plano</label><select class="erp-select" id="bud-account" name="chart_account_id" required><option value="">Escolha</option><?php foreach ($budgetAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="erp-budget-months">
                        <?php foreach ($months as $i => $label): ?><div class="erp-field"><label for="bud-m<?= $i + 1 ?>"><?= $label ?> (R$)</label><input class="erp-input" type="text" id="bud-m<?= $i + 1 ?>" name="month" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$"></div><?php endforeach; ?>
                    </div>
                    <div class="erp-form__actions"><button type="button" class="erp-btn" data-budget-fill>Repetir janeiro nos outros meses</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar previsão</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="bud-title">
        <div class="erp-card__head"><div><h2 id="bud-title">Previsão de <?= (int)$budgetYear ?></h2><p><?= $this->e($condoName) ?> · <?= count($rows) ?> conta(s) com previsão</p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nenhuma previsão em <?= (int)$budgetYear ?></strong><p>Escolha uma conta acima e informe o previsto de cada mês.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="bud-title" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Conta</th><th scope="col">Natureza</th><th scope="col" class="is-num">Previsto</th><th scope="col" class="is-num">Lançado</th><th scope="col" class="is-num">Diferença</th><th scope="col" class="is-num">Variação</th><?php if ($canManageSettings): ?><th scope="col"><span class="erp-sr-only">Ações</span></th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($rows as $r): ?><tr>
                    <td><?= $this->e($r['code']) ?></td><td><?= $this->e($r['name']) ?></td><td><?= $r['nature'] === 'receita' ? 'Receita' : 'Despesa' ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(ReportScreens::brl((int)$r['budget_total'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(ReportScreens::brl((int)$r['actual_total'])) ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(ReportScreens::brl((int)$r['difference_cents'])) ?></td><td class="is-num erp-nowrap"><?= $r['percent'] === null ? '<span class="erp-muted">—</span>' : $this->e(number_format((float)$r['percent'], 2, ',', '.') . '%') ?></td>
                    <?php if ($canManageSettings): ?><td><button type="button" class="erp-btn erp-btn--sm" data-budget-edit="<?= (int)$r['chart_account_id'] ?>" data-months="<?= $this->e(json_encode(array_map($plain, array_values($r['budget'])))) ?>"><?= $icon('edit', 'erp-icon--sm') ?>Editar</button></td><?php endif; ?>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
