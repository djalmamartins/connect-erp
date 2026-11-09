<?php
/**
 * Relatórios de cobrança (AP-047, AP-048, AP-057 a AP-061, AP-071 e AP-072; ADR 0081 e 0077) com dados
 * reais. O controlador entrega as colunas, as linhas e a URL do CSV prontas; a tela só formata.
 * "Exportar CSV" baixa a mesma consulta pela API (format=csv), com as células protegidas contra
 * fórmula e a exportação na auditoria.
 * @var array $currentPage @var string $currentPageId @var string $cReportKind @var ?string $cReportVariant @var array $cReportFilters
 * @var ?string $cReportError @var ?array $cReport @var list<array> $cReportColumns @var list<array> $cReportRows @var ?string $cReportCsv
 */
use Source\Controllers\Erp\Connect\CollectionReportScreens as Screens;

$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (mixed $value): string => is_string($value) && $value !== '' ? date('d/m/Y', (int)strtotime($value)) : '—';
$condo = \Source\Support\Erp\CondominiumContext::current();
$scopeLabel = $condo !== null ? (string)$condo['name'] : 'Todos os condomínios do seu escopo';
$kind = $cReportKind;
$f = $cReportFilters;
$period = $kind !== 'delinquents';
$statusTone = ['active' => 'success', 'breached' => 'danger', 'cancelled' => 'plain'];
$leads = [
    'agreements' => 'Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.',
    'agreement-fees' => 'Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos = encargos − descontos.',
    'delinquents' => 'Contas a receber vencidas e em aberto na data de referência, com os encargos sugeridos pela regra de cobrança de cada condomínio.',
    'receipts' => 'Receitas pagas no período, pela data do pagamento.',
];
$cell = function (array $row, array $column) use ($brl, $date, $statusTone): string {
    [, $path, $type] = $column;
    $value = Screens::value($row, $path);
    return match ($type) {
        'money' => $this->e($brl((int)$value)),
        'date' => $this->e($date($value)),
        'days' => (int)$value . ' dia(s)',
        'int' => (string)(int)$value,
        'paid' => (int)$value . ' de ' . (int)($row['installments'] ?? 0),
        'status' => '<span class="erp-badge erp-badge--' . ($statusTone[$value] ?? 'plain') . '">' . $this->e(Screens::AGREEMENT_STATUSES[$value] ?? (string)$value) . '</span>',
        default => $value === null || $value === '' ? '<span class="erp-muted">—</span>' : $this->e((string)$value),
    };
};
$numeric = static fn (string $type): bool => in_array($type, ['money', 'int', 'days', 'paid'], true);

ob_start(); ?>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
    <?php if ($cReportCsv !== null && $cReportRows !== []): ?><a class="erp-btn erp-btn--primary" href="<?= $this->e(url($cReportCsv)) ?>" download><?= $icon('download', 'erp-icon--sm') ?>Exportar CSV</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => $leads[$kind] ?? '',
    'pageActions' => $actions,
]);
?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtros do relatório">
    <?php if ($period): ?>
        <div class="erp-field erp-field--compact"><label for="cr-from">De</label><input class="erp-input" type="date" id="cr-from" name="de" value="<?= $this->e($f['de']) ?>" required></div>
        <div class="erp-field erp-field--compact"><label for="cr-to">Até</label><input class="erp-input" type="date" id="cr-to" name="ate" value="<?= $this->e($f['ate']) ?>" required></div>
    <?php else: ?>
        <div class="erp-field erp-field--compact"><label for="cr-ref">Data de referência</label><input class="erp-input" type="date" id="cr-ref" name="referencia" value="<?= $this->e($f['referencia']) ?>" required></div>
    <?php endif; ?>
    <?php if ($kind === 'agreements'): ?>
        <div class="erp-field erp-field--compact"><label for="cr-status">Situação</label><select class="erp-select" id="cr-status" name="situacao"><option value="">Todas</option><?php foreach (Screens::AGREEMENT_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $f['situacao'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($f['agrupar'] !== null): ?>
        <div class="erp-field erp-field--compact"><label for="cr-group">Agrupar por</label><select class="erp-select" id="cr-group" name="agrupar"><?php foreach (Screens::ACCOUNT_GROUPS as $k => $l): ?><option value="<?= $k ?>"<?= $f['agrupar'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($cReportError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível gerar o relatório</strong><?= $this->e($cReportError) ?></div></div>
<?php else: ?>
    <?php if ($cReportVariant === 'resident'): ?>
        <div class="erp-alert" role="note"><?= $icon('lock') ?><div>Dados pessoais: o documento aparece mascarado, e a exportação fica registrada na auditoria.</div></div>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="cr-title">
        <div class="erp-card__head"><div><h2 id="cr-title"><?= $this->e($currentPage['name']) ?></h2><p><?= $this->e($scopeLabel) ?> · <?= $period ? 'de ' . $this->e($date($f['de'])) . ' a ' . $this->e($date($f['ate'])) : 'em ' . $this->e($date($f['referencia'])) ?> · <?= count($cReportRows) ?> linha(s) · emitido em <?= date('d/m/Y') ?></p></div></div>
        <?php if ($cReportRows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nada neste filtro</strong><p><?= $period ? 'Não há registros no período escolhido.' : 'Não há conta vencida em aberto nesta data.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="cr-title" tabindex="0"><table class="erp-table erp-report-table">
                <thead><tr><?php foreach ($cReportColumns as $c): ?><th scope="col"<?= $numeric($c[2]) ? ' class="is-num"' : '' ?>><?= $this->e($c[0]) ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($cReportRows as $row): ?><tr<?= ($row['status'] ?? null) === 'cancelled' ? ' class="erp-muted"' : '' ?>><?php foreach ($cReportColumns as $c): ?><td<?= $c[2] === 'text' ? '' : ' class="' . ($numeric($c[2]) ? 'is-num ' : '') . 'erp-nowrap"' ?>><?= $cell($row, $c) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                <tfoot><tr><?php foreach ($cReportColumns as $i => $c): ?><?php if ($i === 0): ?><th scope="row">Total<?= $kind === 'agreements' ? ' (sem cancelados)' : '' ?></th><?php elseif ($c[2] === 'money'): ?><td class="is-num"><?= $this->e($brl(array_sum(array_map(static fn (array $r): int => ($r['status'] ?? null) === 'cancelled' ? 0 : (int)Screens::value($r, $c[1]), $cReportRows)))) ?></td><?php else: ?><td></td><?php endif; ?><?php endforeach; ?></tr></tfoot>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
