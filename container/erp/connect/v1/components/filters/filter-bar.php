<?php
/**
 * Barra de filtros. Chaves: condo, search, status, period, city, category.
 * O filtro de texto age sobre a tabela da página (dados de demonstração).
 * @var list<string> $filterKeys
 * @var ?array $filterStatus  definição da coluna de situação, quando houver
 * @var string $filterTarget  id da tabela
 */
$prefix = 'flt-' . $filterTarget;
?>
<form class="erp-filters" role="search" aria-label="Filtros" onsubmit="return false">
    <?php foreach ($filterKeys as $key): ?>
        <?php if ($key === 'search'): ?>
            <div class="erp-field erp-field--grow"><label for="<?= $prefix ?>-q">Pesquisar</label><input class="erp-input" type="search" id="<?= $prefix ?>-q" placeholder="Digite para filtrar a lista" data-erp-table-filter></div>
        <?php elseif ($key === 'condo'): ?>
            <div class="erp-field"><label for="<?= $prefix ?>-condo">Condomínio</label><select class="erp-select" id="<?= $prefix ?>-condo" data-erp-select-filter><option value="">Todos</option><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select></div>
        <?php elseif ($key === 'status' && $filterStatus): ?>
            <div class="erp-field"><label for="<?= $prefix ?>-status">Situação</label><select class="erp-select" id="<?= $prefix ?>-status" data-erp-select-filter><option value="">Todas</option><?php foreach ($filterStatus['options'] as $option): ?><option><?= $this->e($option) ?></option><?php endforeach; ?></select></div>
        <?php elseif ($key === 'period'): ?>
            <div class="erp-field"><label for="<?= $prefix ?>-from">De</label><input class="erp-input" type="date" id="<?= $prefix ?>-from" value="2026-10-01"></div>
            <div class="erp-field"><label for="<?= $prefix ?>-to">Até</label><input class="erp-input" type="date" id="<?= $prefix ?>-to" value="2026-11-30"></div>
        <?php elseif ($key === 'city'): ?>
            <div class="erp-field"><label for="<?= $prefix ?>-city">Cidade</label><select class="erp-select" id="<?= $prefix ?>-city" data-erp-select-filter><option value="">Todas</option><?php foreach ($vocabulary['cities'] ?? [] as $city): ?><option><?= $this->e($city) ?>/SP</option><?php endforeach; ?></select></div>
        <?php elseif ($key === 'category'): ?>
            <div class="erp-field"><label for="<?= $prefix ?>-cat">Categoria</label><select class="erp-select" id="<?= $prefix ?>-cat" data-erp-select-filter><option value="">Todas</option><?php foreach (['Manutenção', 'Limpeza', 'Segurança', 'Elevadores', 'Jardinagem'] as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="reset" class="erp-btn erp-btn--ghost" data-erp-filter-reset>Limpar filtros</button>
</form>
