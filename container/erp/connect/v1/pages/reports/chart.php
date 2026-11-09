<?php
/**
 * Gráficos (AP-102 a AP-109; ADR 0083 e 0078) com dados reais. O controlador entrega os painéis já
 * medidos e a tabela equivalente; a tela só desenha e formata. Um eixo por painel; a tabela abaixo
 * traz os mesmos números, para leitura sem cor e para leitores de tela.
 * @var array $currentPage @var string $chartKind @var array $chartFilters @var ?string $chartError @var list<array> $chartUnits
 * @var bool $chartNeedsUnit @var list<array> $chartPanels @var ?array $chartTable
 */
use Source\Controllers\Erp\Connect\ChartScreens as Screens;

$this->layout('default');
$route = erp_url($currentPage['route']);
$f = $chartFilters;
$condo = \Source\Support\Erp\CondominiumContext::current();
$scopeLabel = $condo !== null ? (string)$condo['name'] : 'Todos os condomínios do seu escopo';
$period = 'de ' . Screens::monthLabel($f['de']) . ' a ' . Screens::monthLabel($f['ate']);
$leads = [
    'general' => 'Receitas e despesas previstas por competência, o resultado do mês e o que venceu e segue em aberto.',
    'accounts' => 'Valores por conta do plano, na competência. As oito maiores aparecem; as demais somam em "Outras".',
    'consumption-unit' => 'Consumo medido da unidade, por tipo de medidor e competência.',
    'consumption' => 'Consumo medido por tipo de medidor e competência, somando as unidades.',
    'cash' => 'Recebido e pago por mês do pagamento (caixa) e o saldo de cada mês.',
    'delinquency' => 'Por competência: o cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.',
    'bank-accounts' => 'Saldo de cada mês (entradas − saídas, com as transferências) por conta bancária.',
    'common-areas' => 'Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".',
];
$unitNames = ['cents' => 'em reais', 'percent' => 'em %', 'consumption' => 'consumo medido', 'count' => 'quantidade'];
$panelsEmpty = $chartPanels === [] || array_filter($chartPanels, static fn (array $p): bool => !$p['empty']) === [];

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ' · Gráficos',
    'pageTitle' => $currentPage['name'],
    'pageLead' => $leads[$chartKind] ?? '',
    'pageActions' => $actions,
]);
?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Parâmetros do gráfico">
    <div class="erp-field erp-field--compact"><label for="chart-from">De</label><input class="erp-input" type="month" id="chart-from" name="de" value="<?= $this->e($f['de']) ?>" required></div>
    <div class="erp-field erp-field--compact"><label for="chart-to">Até</label><input class="erp-input" type="month" id="chart-to" name="ate" value="<?= $this->e($f['ate']) ?>" required></div>
    <?php if ($chartKind === 'accounts'): ?>
        <div class="erp-field erp-field--compact"><label for="chart-nature">Natureza</label><select class="erp-select" id="chart-nature" name="natureza"><?php foreach (Screens::NATURES as $k => $l): ?><option value="<?= $k ?>"<?= $f['natureza'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($chartKind === 'consumption-unit'): ?>
        <div class="erp-field erp-field--grow"><label for="chart-unit">Unidade</label><select class="erp-select" id="chart-unit" name="unidade" required>
            <option value="">Escolha a unidade</option>
            <?php foreach ($chartUnits as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['unidade'] === (int)$u['id'] ? ' selected' : '' ?>><?= $this->e($u['label']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($chartError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível montar o gráfico</strong><?= $this->e($chartError) ?></div></div>
<?php elseif ($chartNeedsUnit): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Escolha a unidade</strong>
        <p><?= $chartUnits === [] ? 'Não há unidade ativa no seu escopo. Escolha um condomínio na barra superior ou cadastre as unidades.' : 'O gráfico mostra o consumo dos medidores da unidade escolhida.' ?></p></div></section>
<?php elseif ($panelsEmpty): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Sem dados no período</strong><p><?= $this->e($scopeLabel . ' · ' . $period) ?>. Tente outro período ou outro condomínio.</p></div></section>
<?php else: ?>
    <?php foreach ($chartPanels as $n => $panel): $panelId = 'chart-panel-' . $n; $many = count($panel['series']) > 1; ?>
        <section class="erp-card erp-vchart-card" aria-labelledby="<?= $panelId ?>">
            <div class="erp-card__head"><div>
                <h2 id="<?= $panelId ?>"><?= $this->e($panel['title'] ?? $currentPage['name']) ?></h2>
                <p><?= $this->e($scopeLabel . ' · ' . $period . ' · ' . ($unitNames[$panel['unit']] ?? $panel['unit'])) ?><?= $panel['stacked'] ? ' · barras empilhadas' : '' ?></p>
            </div></div>
            <?php if ($many): ?>
                <ul class="erp-vchart__legend" aria-label="Legenda"><?php foreach ($panel['series'] as $s): ?><li><i class="erp-swatch erp-swatch--<?= $this->e($s['color']) ?>"></i><?= $this->e($s['label']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <div class="erp-vchart" aria-hidden="true">
                <div class="erp-vchart__axis">
                    <span style="bottom: 100%"><?= $this->e(Screens::format($panel['top'], $panel['unit'])) ?></span>
                    <?php if ($panel['bottom'] < 0): ?><span style="bottom: <?= $panel['zero'] ?>%"><?= $this->e(Screens::format(0, $panel['unit'])) ?></span><span style="bottom: 0"><?= $this->e(Screens::format($panel['bottom'], $panel['unit'])) ?></span><?php else: ?><span style="bottom: 0"><?= $this->e(Screens::format(0, $panel['unit'])) ?></span><?php endif; ?>
                </div>
                <div class="erp-vchart__scroll"><div class="erp-vchart__body" style="--erp-vchart-cols: <?= count($panel['columns']) ?>; --erp-vchart-bars: <?= $panel['stacked'] ? 1 : count($panel['series']) ?>">
                    <div class="erp-vchart__plot">
                        <span class="erp-vchart__zero" style="bottom: <?= $panel['zero'] ?>%"></span>
                        <?php foreach ($panel['columns'] as $column): ?>
                            <div class="erp-vchart__col<?= $panel['stacked'] ? ' is-stacked' : '' ?>">
                                <div class="erp-vchart__cluster">
                                <?php foreach ($column['bars'] as $i => [$base, $height, $value]): $s = $panel['series'][$i]; ?>
                                    <span class="erp-vchart__bar erp-swatch--<?= $this->e($s['color']) ?><?= $value !== null && $value < 0 ? ' is-negative' : '' ?>" style="bottom: <?= $base ?>%; height: <?= $height ?>%<?= $panel['stacked'] ? '' : '; --erp-vchart-i: ' . $i ?>"
                                        title="<?= $this->e($s['label'] . ' · ' . Screens::monthLabel($column['month']) . ': ' . Screens::format($value, $panel['unit'])) ?>"></span>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="erp-vchart__labels"><?php foreach ($panel['columns'] as $column): ?><span><?= $this->e(Screens::monthLabel($column['month'])) ?></span><?php endforeach; ?></div>
                </div></div>
            </div>
        </section>
    <?php endforeach; ?>
    <section class="erp-card" aria-labelledby="chart-table-title">
        <div class="erp-card__head"><div><h2 id="chart-table-title">Tabela do gráfico</h2><p>Os mesmos números, mês a mês.</p></div></div>
        <div class="erp-table-wrap" role="region" aria-labelledby="chart-table-title" tabindex="0"><table class="erp-table erp-report-table">
            <thead><tr><th scope="col">Mês</th><?php foreach ($chartTable['series'] as $s): ?><th scope="col" class="is-num"><?= $this->e($s['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($chartTable['months'] as $i => $month): ?><tr><th scope="row"><?= $this->e(Screens::monthLabel($month)) ?></th><?php foreach ($chartTable['series'] as $s): ?><td class="is-num erp-nowrap"><?= $this->e(Screens::format($s['data'][$i] ?? null, $s['unit'])) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
            <tfoot><tr><th scope="row">Total</th><?php foreach ($chartTable['series'] as $s): ?><td class="is-num erp-nowrap"><?= $s['total'] === null ? '<span class="erp-muted">—</span>' : $this->e(Screens::format($s['total'], $s['unit'])) ?></td><?php endforeach; ?></tr></tfoot>
        </table></div>
    </section>
<?php endif; ?>
