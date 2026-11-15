<?php
/**
 * Paginação padrão das listas do ERP (revisão de 15/11, ADR 0310): "Exibindo 1–50 de 320 itens", os números das
 * páginas pelo moves/pager (Source\Support\Pager) e "Itens por página" com 50 (padrão), 100, 200 e 500.
 * A escolha vai em ?por_pagina= e volta para a página 1; os outros filtros da tela continuam na URL.
 *
 * @var array{total:int, page:int, pages:int, per_page:int} $pagination
 * @var string $paginationBase  rota da tela (erp_url(...)), sem query
 * @var array<string, scalar|null> $paginationQuery  filtros atuais (sem pagina e por_pagina)
 * @var ?string $paginationPageParam  nome do parâmetro de página (padrão: pagina)
 */
use Source\Support\Erp\PerPage;

$total = (int)($pagination['total'] ?? 0);
$page = max(1, (int)($pagination['page'] ?? 1));
$perPage = (int)($pagination['per_page'] ?? PerPage::DEFAULT);
$pageParam = $paginationPageParam ?? 'pagina';
$keep = array_filter($paginationQuery ?? [], static fn ($v): bool => $v !== null && $v !== '' && $v !== false);
unset($keep[$pageParam], $keep['por_pagina']);
if ($perPage !== PerPage::DEFAULT) {
    $keep['por_pagina'] = $perPage;
}
$from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to = min($total, $page * $perPage);
$pagerHtml = null;
if ($total > $perPage) {
    // O moves/pager acrescenta o número da página no fim do link, mas troca qualquer "page" do link pelo número:
    // nos filtros, "page" vai como "pag%65" (o mesmo texto depois de decodificado), para uma pesquisa por
    // "homepage" não virar "home3".
    $link = $paginationBase . '?' . ($keep === [] ? '' : str_replace('page', 'pag%65', http_build_query($keep)) . '&') . $pageParam . '=';
    $pager = new \Source\Support\Pager($link, 'Página', ['Primeira página', '«'], ['Última página', '»']);
    $pagerHtml = $pager->pager($total, $perPage, $page, 2)->render('erp-pager');
}
$formKeep = $keep;
unset($formKeep['por_pagina']);
?>
<nav class="erp-pagination" aria-label="Paginação">
    <span>Exibindo <strong><?= $from ?>–<?= $to ?></strong> de <strong><?= number_format($total, 0, ',', '.') ?></strong> <?= $total === 1 ? 'item' : 'itens' ?></span>
    <?php if ($pagerHtml !== null): ?><div class="erp-pagination__pages"><?= $pagerHtml ?></div><?php endif; ?>
    <form class="erp-per-page" method="get" action="<?= $this->e($paginationBase) ?>">
        <?php foreach ($formKeep as $key => $value): ?><input type="hidden" name="<?= $this->e((string)$key) ?>" value="<?= $this->e((string)$value) ?>"><?php endforeach; ?>
        <label for="per-page-<?= $this->e(substr(md5($paginationBase), 0, 6)) ?>">Itens por página</label>
        <select class="erp-select erp-select--sm" id="per-page-<?= $this->e(substr(md5($paginationBase), 0, 6)) ?>" name="por_pagina" data-erp-autosubmit>
            <?php foreach (PerPage::OPTIONS as $option): ?><option value="<?= $option ?>"<?= $option === $perPage ? ' selected' : '' ?>><?= $option ?></option><?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="erp-btn erp-btn--sm">Aplicar</button></noscript>
    </form>
</nav>
