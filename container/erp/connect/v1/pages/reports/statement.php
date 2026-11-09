<?php
/**
 * Demonstrativo / prestação de contas por caixa (AP-051, ADR 0037) com dados reais: $statement de
 * Panel::reportData, no contexto da barra superior (?de=AAAA-MM-DD&ate=AAAA-MM-DD, até 366 dias).
 * Recebido e pago por conta do plano, movimento de cada conta bancária e a conciliação entre os dois,
 * com a diferença sempre à mostra.
 * @var array $currentPage @var ?array $statement @var ?string $reportError @var array{from:string,to:string} $reportPeriod
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (string $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? date('d/m/Y', (int)strtotime($value)) : $value;
$condo = \Source\Support\Erp\CondominiumContext::current();
$scopeLabel = $condo !== null ? (string)$condo['name'] : 'Todos os condomínios do seu escopo';
$accounts = $statement['accounts'] ?? [];
$banks = $statement['banks'] ?? [];
$totals = $statement['totals'] ?? [];
$t = static fn (string $key): int => (int)($totals[$key] ?? 0);
$bankSum = static fn (string $key): int => array_sum(array_map(static fn (array $b): int => (int)$b[$key], $banks));

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos.',
    'pageActions' => $actions,
]);
?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Período do demonstrativo">
    <div class="erp-field erp-field--compact"><label for="st-from">De</label><input class="erp-input" type="date" id="st-from" name="de" value="<?= $this->e($reportPeriod['from']) ?>" required></div>
    <div class="erp-field erp-field--compact"><label for="st-to">Até</label><input class="erp-input" type="date" id="st-to" name="ate" value="<?= $this->e($reportPeriod['to']) ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($reportError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível gerar o demonstrativo</strong><?= $this->e($reportError) ?></div></div>
<?php else: ?>
    <?php if (!empty($totals['reconciled'])): ?>
        <div class="erp-alert erp-alert--success" role="status"><?= $icon('check') ?><div><strong>Conciliado</strong>O resultado pelo plano de contas é igual ao movimento das contas bancárias no período.</div></div>
    <?php else: ?>
        <div class="erp-alert erp-alert--warning" role="status"><?= $icon('alert') ?><div><strong>Diferença de <?= $brl($t('difference_cents')) ?> entre o plano de contas e os bancos</strong>Há lançamento pago sem conta bancária ou numa conta bancária excluída. Confira os lançamentos pagos do período.</div></div>
    <?php endif; ?>

    <section class="erp-grid erp-grid--kpi" aria-label="Resumo do período">
        <?php foreach ([
            ['label' => 'Recebido', 'value' => $brl($t('received_cents')), 'meta' => 'Receitas pagas no período', 'icon' => 'arrow-down', 'tone' => '', 'trend' => null],
            ['label' => 'Pago', 'value' => $brl($t('paid_cents')), 'meta' => 'Despesas pagas no período', 'icon' => 'arrow-up', 'tone' => 'warning', 'trend' => null],
            ['label' => 'Resultado do caixa', 'value' => $brl($t('result_cents')), 'meta' => 'Recebido menos pago', 'icon' => 'scale', 'tone' => $t('result_cents') < 0 ? 'danger' : 'info', 'trend' => null],
            ['label' => 'Saldo final nos bancos', 'value' => $brl($bankSum('closing_cents')), 'meta' => 'Anterior: ' . $brl($bankSum('opening_cents')), 'icon' => 'wallet', 'tone' => '', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="st-accounts">
        <div class="erp-card__head"><div><h2 id="st-accounts">Receitas e despesas de <?= $this->e($date($reportPeriod['from'])) ?> a <?= $this->e($date($reportPeriod['to'])) ?></h2><p><?= $this->e($scopeLabel) ?> · regime de caixa (data do pagamento) · emitido em <?= date('d/m/Y') ?></p></div></div>
        <?php if ($accounts === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('receipt') ?></span><strong>Nada recebido nem pago no período</strong><p>Não há lançamentos pagos entre estas datas.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="st-accounts" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><th>Conta do plano</th><th class="is-num">Valor</th></tr></thead>
                <?php foreach (['receita' => ['Receitas recebidas', 'received_cents'], 'despesa' => ['Despesas pagas', 'paid_cents']] as $nature => [$label, $key]): $group = array_values(array_filter($accounts, static fn (array $a): bool => $a['nature'] === $nature)); if ($group === []) continue; ?>
                    <tbody>
                        <tr class="erp-report-group"><th scope="rowgroup" colspan="2"><?= $label ?></th></tr>
                        <?php foreach ($group as $row): ?><tr><td><span class="erp-report-account"><span class="erp-report-code"><?= $this->e((string)$row['code']) ?></span> <?= $this->e((string)$row['name']) ?></span></td><td class="is-num"><?= $brl((int)$row['total_cents']) ?></td></tr><?php endforeach; ?>
                        <tr class="erp-report-total"><td>Total</td><td class="is-num"><?= $brl($t($key)) ?></td></tr>
                    </tbody>
                <?php endforeach; ?>
                <tfoot><tr><td>Resultado do caixa</td><td class="is-num"><?= $brl($t('result_cents')) ?></td></tr></tfoot>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="erp-card" aria-labelledby="st-banks">
        <div class="erp-card__head"><div><h2 id="st-banks">Contas bancárias</h2><p>Saldo anterior (saldo inicial e pagamentos antes do período), entradas, saídas e saldo final</p></div></div>
        <?php if ($banks === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhuma conta bancária</strong><p>Cadastre as contas bancárias do condomínio para conciliar o caixa.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="st-banks" tabindex="0"><table class="erp-table">
                <thead><tr><th>Conta</th><th class="is-num">Saldo anterior</th><th class="is-num">Entradas</th><th class="is-num">Saídas</th><th class="is-num">Saldo final</th></tr></thead>
                <tbody><?php foreach ($banks as $bank): ?>
                    <tr><td><strong><?= $this->e((string)$bank['name']) ?></strong><?php if ($condo === null): ?><small class="erp-muted"><?= $this->e((string)$bank['condominium_name']) ?></small><?php endif; ?></td>
                        <td class="is-num"><?= $brl((int)$bank['opening_cents']) ?></td><td class="is-num"><?= $brl((int)$bank['received_cents']) ?></td><td class="is-num"><?= $brl((int)$bank['paid_cents']) ?></td><td class="is-num"><?= $brl((int)$bank['closing_cents']) ?></td></tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><td>Total</td><td class="is-num"><?= $brl($bankSum('opening_cents')) ?></td><td class="is-num"><?= $brl($bankSum('received_cents')) ?></td><td class="is-num"><?= $brl($bankSum('paid_cents')) ?></td><td class="is-num"><?= $brl($bankSum('closing_cents')) ?></td></tr></tfoot>
            </table></div>
        <?php endif; ?>
        <dl class="erp-report-reconcile" aria-label="Conciliação">
            <div><dt>Resultado pelo plano de contas</dt><dd><?= $brl($t('result_cents')) ?></dd></div>
            <div><dt>Movimento líquido dos bancos</dt><dd><?= $brl($t('bank_net_cents')) ?></dd></div>
            <div class="<?= !empty($totals['reconciled']) ? '' : 'is-off' ?>"><dt>Diferença</dt><dd><?= $brl($t('difference_cents')) ?></dd></div>
        </dl>
    </section>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
