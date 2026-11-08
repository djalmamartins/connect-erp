<?php
/**
 * Tabela com ordenação e paginação no cliente (dados de demonstração) ou estado vazio.
 * @var string $tableId
 * @var string $tableCaption
 * @var list<array> $tableColumns  definições FieldSpec
 * @var list<list<array{0: string, 1: string}>> $tableRows
 * @var bool $tableActions  ações por linha (ver, editar, excluir)
 * @var ?string $tableEntity
 * @var bool $tableTotals  soma das colunas de valor no rodapé
 */
$tableActions ??= false;
$tableTotals ??= false;
$badge = static function (string $value): string {
    $v = mb_strtolower($value);
    $tone = match (true) {
        (bool)preg_match('/vencid|atras|rompid|crític|erro|bloquead|rejeitad|cancelad|devolvid|expirad|inativ|baixad/u', $v) => 'danger',
        (bool)preg_match('/pendente|aguard|a vencer|a renovar|atenção|em andamento|agendad|em preparação|manutenção|parcela|notifica|rascunho|convocad|em obra|em implantação|em trânsito|prevista|em execução/u', $v) => 'warning',
        (bool)preg_match('/ativo|pag|recebid|quitad|em dia|vigente|concluíd|confirmad|resolvid|publicad|enviad|processad|registrad|aprovad|retirad|realizad|conectad|liberad|no ar|ocupad|bom|devolvido|entregue|vinculad|conferid|liquidad|empenhad|encerrad/u', $v) => 'success',
        default => 'info',
    };
    return '<span class="erp-badge erp-badge--' . $tone . '">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
};
$totals = [];
?>
<?php if ($tableRows === []): ?>
    <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'file', 'emptyTitle' => 'Nenhum registro ainda', 'emptyText' => 'Os registros aparecerão aqui quando o módulo estiver integrado ao banco. Nenhum dado fictício é exibido com o modo demonstração desligado.']); ?>
<?php else: ?>
    <div class="erp-table-wrap" data-erp-table data-per-page="8" role="region" aria-label="<?= $this->e($tableCaption) ?>" tabindex="0" id="<?= $this->e($tableId) ?>">
        <table class="erp-table">
            <caption class="erp-sr-only"><?= $this->e($tableCaption) ?></caption>
            <thead>
                <tr>
                    <?php foreach ($tableColumns as $index => $column): ?>
                        <th scope="col"<?= in_array($column['type'], ['money', 'int', 'pct'], true) ? ' class="is-num"' : '' ?>><button type="button" class="erp-sort" data-erp-sort="<?= $index ?>"><?= $this->e($column['label']) ?></button></th>
                    <?php endforeach; ?>
                    <?php if ($tableActions): ?><th scope="col" class="is-num"><span class="erp-sr-only">Ações</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableRows as $r => $row): ?>
                    <tr>
                        <?php foreach ($row as $index => [$text, $sort]):
                            $column = $tableColumns[$index];
                            $num = in_array($column['type'], ['money', 'int', 'pct'], true);
                            if ($column['type'] === 'money') { $totals[$index] = ($totals[$index] ?? 0) + (int)$sort; } ?>
                            <td<?= $num ? ' class="is-num"' : '' ?> data-sort="<?= $this->e($sort) ?>"><?php if ($column['type'] === 'status'): ?><?= $badge($text) ?><?php elseif ($index === 0): ?><strong><?= $this->e($text) ?></strong><?php else: ?><?= $this->e($text) ?><?php endif; ?></td>
                        <?php endforeach; ?>
                        <?php if ($tableActions): ?>
                            <td class="is-num"><div class="erp-table__actions">
                                <button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-open="<?= $this->e($tableId) ?>-detail" aria-label="Ver <?= $this->e($tableEntity ?? 'registro') ?> <?= $this->e($row[0][0]) ?>"><?= $icon('eye', 'erp-icon--sm') ?></button>
                                <button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-open="<?= $this->e($tableId) ?>-form" aria-label="Editar <?= $this->e($row[0][0]) ?>"><?= $icon('edit', 'erp-icon--sm') ?></button>
                                <button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-open="<?= $this->e($tableId) ?>-delete" aria-label="Excluir <?= $this->e($row[0][0]) ?>"><?= $icon('trash', 'erp-icon--sm') ?></button>
                            </div></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($tableTotals && $totals !== []): ?>
                <tfoot><tr>
                    <?php foreach ($tableColumns as $index => $column): ?>
                        <td<?= isset($totals[$index]) ? ' class="is-num"' : '' ?>><?= $index === 0 ? 'Total' : (isset($totals[$index]) ? 'R$ ' . number_format($totals[$index] / 100, 2, ',', '.') : '') ?></td>
                    <?php endforeach; ?>
                    <?php if ($tableActions): ?><td></td><?php endif; ?>
                </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <div class="erp-pagination" data-erp-pager>
        <span data-erp-pager-info></span>
        <div class="erp-pagination__pages" data-erp-pager-pages></div>
    </div>
<?php endif; ?>
