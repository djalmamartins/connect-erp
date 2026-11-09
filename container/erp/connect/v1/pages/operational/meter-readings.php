<?php
/**
 * Leituras do mês (AP-042, ADR 0045 e 0047) com dados reais, no contexto da barra superior: uma linha
 * por relógio ativo do tipo ($meterSheet de Panel::meterReadingData), com a leitura anterior, a da
 * competência, o consumo e o valor. Lançar em lote e faturar usam a API /api/v1/meter-readings
 * (erp.js, data-erp-meter-readings). Leitura já cobrada não muda.
 * @var array $currentPage @var list<array> $meterSheet @var string $meterCompetence @var string $meterKind @var bool $canManageReadings
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$kinds = \Source\Controllers\Erp\Connect\Panel::METER_KINDS;
$unitOf = $meterKind === 'energia' ? 'kWh' : 'm³';
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$qty = static fn (float $value): string => number_format($value, 3, ',', '.');
$month = static fn (string $value): string => substr($value, 5, 2) . '/' . substr($value, 0, 4);
$canManageReadings ??= false;
$context = \Source\Support\Erp\CondominiumContext::id();
$rows = $meterSheet;
$read = array_values(array_filter($rows, static fn (array $r): bool => $r['reading'] !== null));
$consumption = array_sum(array_map(static fn (array $r): float => (float)$r['reading']['consumption'], $read));
$amount = array_sum(array_map(static fn (array $r): int => (int)$r['reading']['amount_cents'], $read));
$billed = count(array_filter($read, static fn (array $r): bool => !empty($r['reading']['billed'])));
$billable = count(array_filter($read, static fn (array $r): bool => empty($r['reading']['billed']) && (int)$r['reading']['amount_cents'] > 0));
$noTariff = count(array_filter($read, static fn (array $r): bool => $r['reading']['tariff_valid_from'] === null && (float)$r['reading']['consumption'] > 0));
$manyCondos = count(array_unique(array_column($rows, 'condominium_id'))) > 1;
$routes = static fn (string $id): string => erp_url($catalog['pages'][$id]['route'] ?? '');

ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($routes('AP-041')) ?>">Relógios</a>
    <a class="erp-btn" href="<?= $this->e($routes('AP-043')) ?>">Tarifas</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber.',
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-meter-readings data-api="<?= $this->e(url('/api/v1/meter-readings')) ?>" data-chart-api="<?= $this->e(url('/api/v1/chart-accounts')) ?>" data-condominium="<?= $context !== null ? (int)$context : '' ?>" data-competence="<?= $this->e($meterCompetence) ?>" data-kind="<?= $this->e($meterKind) ?>">
    <?= csrf_input() ?>
    <form class="erp-card erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Competência e tipo">
        <div class="erp-field erp-field--compact"><label for="mr-month">Competência</label><input class="erp-input" type="month" id="mr-month" name="competencia" value="<?= $this->e($meterCompetence) ?>" required></div>
        <div class="erp-field erp-field--compact"><label for="mr-kind">Tipo</label><select class="erp-select" id="mr-kind" name="tipo"><?php foreach ($kinds as $key => $label): ?><option value="<?= $key ?>"<?= $key === $meterKind ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Mostrar</button></div>
    </form>

    <section class="erp-grid erp-grid--kpi" aria-label="Resumo de <?= $this->e($kinds[$meterKind] . ' em ' . $month($meterCompetence)) ?>">
        <?php foreach ([
            ['label' => 'Relógios lidos', 'value' => count($read) . ' de ' . count($rows), 'meta' => $kinds[$meterKind] . ' · ' . $month($meterCompetence), 'icon' => 'clock', 'tone' => count($read) < count($rows) ? 'warning' : '', 'trend' => null],
            ['label' => 'Consumo', 'value' => $qty($consumption) . ' ' . $unitOf, 'meta' => 'Soma das leituras lançadas', 'icon' => 'chart', 'tone' => 'info', 'trend' => null],
            ['label' => 'Valor', 'value' => $brl($amount), 'meta' => 'Pelas faixas vigentes na data da leitura', 'icon' => 'wallet', 'tone' => '', 'trend' => null],
            ['label' => 'Cobradas', 'value' => (string)$billed, 'meta' => $billable . ' pronta(s) para faturar', 'icon' => 'check', 'tone' => $billable > 0 ? 'warning' : '', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <?php if ($noTariff > 0): ?>
        <div class="erp-alert erp-alert--warning" role="status"><?= $icon('alert') ?><div><strong><?= $noTariff ?> leitura(s) sem tarifa vigente</strong>O valor ficou zero e elas não entram na cobrança. Cadastre as faixas em <a href="<?= $this->e($routes('AP-043')) ?>">Tarifas</a> e lance a leitura de novo.</div></div>
    <?php endif; ?>

    <section class="erp-card" aria-labelledby="mr-sheet">
        <form data-meter-readings-form novalidate>
            <div class="erp-card__head">
                <div><h2 id="mr-sheet">Leituras de <?= $this->e(mb_strtolower($kinds[$meterKind])) ?> · <?= $this->e($month($meterCompetence)) ?></h2><p>Consumo = leitura do mês − leitura anterior. Leitura menor que a anterior é recusada; na troca de relógio, cadastre o relógio novo.</p></div>
                <?php if ($canManageReadings && $rows !== []): ?>
                    <div class="erp-meter-head-actions">
                        <div class="erp-field erp-field--compact"><label for="mr-date">Data da leitura</label><input class="erp-input" type="date" id="mr-date" name="read_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nenhum relógio de <?= $this->e(mb_strtolower($kinds[$meterKind])) ?> ativo</strong><p>Cadastre os relógios das unidades em <a href="<?= $this->e($routes('AP-041')) ?>">Relógios</a><?= $context === null ? '' : ' ou escolha outro condomínio na barra superior' ?>.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="mr-sheet" tabindex="0"><table class="erp-table erp-meter-sheet">
                    <thead><tr><th>Unidade</th><th>Relógio</th><th class="is-num">Anterior</th><th class="is-num">Leitura do mês</th><th class="is-num">Consumo</th><th class="is-num">Valor</th><th>Situação</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): $reading = $r['reading']; $locked = $reading !== null && !empty($reading['billed']); ?>
                        <tr data-meter-row="<?= (int)$r['meter_id'] ?>" data-previous="<?= $this->e(number_format((float)$r['previous'], 3, '.', '')) ?>">
                            <td><strong class="erp-nowrap"><?= $this->e($r['unit_label']) ?></strong><?php if ($manyCondos || $context === null): ?><br><span class="erp-muted"><?= $this->e((string)$r['condominium_name']) ?></span><?php endif; ?></td>
                            <td><?= $this->e($r['number']) ?></td>
                            <td class="is-num"><?= $qty((float)$r['previous']) ?></td>
                            <td class="is-num"><?php if ($canManageReadings && !$locked): ?><input class="erp-input erp-meter-input" name="reading" inputmode="decimal" autocomplete="off" aria-label="Leitura de <?= $this->e($r['unit_label'] . ', relógio ' . $r['number']) ?>" data-meter-id="<?= (int)$r['meter_id'] ?>" data-original="<?= $reading !== null ? $this->e(number_format((float)$reading['reading'], 3, ',', '')) : '' ?>" value="<?= $reading !== null ? $this->e(number_format((float)$reading['reading'], 3, ',', '')) : '' ?>" placeholder="<?= $this->e(number_format((float)$r['previous'], 3, ',', '')) ?>" pattern="^\d{1,9}([.,]\d{1,3})?$"><?php else: ?><?= $reading !== null ? $qty((float)$reading['reading']) : '—' ?><?php endif; ?></td>
                            <td class="is-num" data-meter-consumption><?= $reading !== null ? $qty((float)$reading['consumption']) . ' ' . $unitOf : '—' ?></td>
                            <td class="is-num"><?= $reading !== null ? $this->e($brl((int)$reading['amount_cents'])) : '—' ?></td>
                            <td data-meter-state><?php if ($reading === null): ?><span class="erp-badge erp-badge--plain">Sem leitura</span><?php elseif ($locked): ?><span class="erp-badge erp-badge--success">Cobrada</span><?php elseif ($reading['tariff_valid_from'] === null && (float)$reading['consumption'] > 0): ?><span class="erp-badge erp-badge--warning">Sem tarifa</span><?php else: ?><span class="erp-badge erp-badge--info">Lançada</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($canManageReadings): ?>
                    <div class="erp-form__actions erp-meter-actions">
                        <span class="erp-muted" data-meter-changes role="status" aria-live="polite">Nenhuma leitura alterada.</span>
                        <?php if ($billable > 0): ?><button type="button" class="erp-btn" data-meter-bill<?= $context === null ? ' disabled title="Escolha um condomínio na barra superior para faturar"' : '' ?>><?= $icon('wallet', 'erp-icon--sm') ?>Faturar <?= $billable ?> unidade(s)</button><?php endif; ?>
                        <button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar leituras</button>
                    </div>
                    <?php if ($billable > 0 && $context === null): ?><p class="erp-muted erp-meter-note">Para faturar, escolha um condomínio na barra superior: a cobrança é feita por condomínio.</p><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($canManageReadings && $billable > 0 && $context !== null): ?>
    <dialog class="erp-modal" id="meter-bill" aria-labelledby="meter-bill-title">
        <form data-meter-bill-form>
            <div class="erp-modal__head"><h2 id="meter-bill-title">Faturar <?= $this->e(mb_strtolower($kinds[$meterKind])) ?> de <?= $this->e($month($meterCompetence)) ?></h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p>Gera uma conta a receber para cada uma das <?= $billable ?> leitura(s) com valor e ainda não cobradas, no total de <strong><?= $this->e($brl(array_sum(array_map(static fn (array $r): int => empty($r['reading']['billed']) ? (int)$r['reading']['amount_cents'] : 0, $read)))) ?></strong>.</p>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-8"><label for="mr-chart">Conta do plano</label><select class="erp-select" id="mr-chart" name="chart_account_id" required data-meter-chart><option value="">Carregando…</option></select></div>
                    <div class="erp-field erp-col-4"><label for="mr-due">Vencimento</label><input class="erp-input" type="date" id="mr-due" name="due_on" required></div>
                </div>
                <p class="erp-muted">É tudo ou nada: se uma conta não passar nas regras dos lançamentos (por exemplo, competência fechada), nenhuma é criada. Cobrar de novo não duplica, e a leitura cobrada não muda mais.</p>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('wallet', 'erp-icon--sm') ?>Faturar</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
