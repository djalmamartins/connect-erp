<?php
/**
 * "Sobre esta tela" (ADR 0309): o que a página faz e o principal do que acontece por trás, com o link
 * para o tutorial completo na Central de Ajuda. Texto de Source\Services\Help\PageGuides (a mesma
 * fonte da seção "O que acontece por trás" do artigo).
 *
 * Visual (retorno do Djalma, 15/11/2026, com o modelo que ele mandou): caixa clara de borda fina, título
 * pequeno com ícone, texto curto, até 3 itens e "Abrir tutorial completo". Clicar no título recolhe a
 * caixa e ela fica só com o título; recolhida, continua recolhida naquela tela (erp.js, localStorage).
 *
 * @var ?string $currentPageId
 */
$introGuide = \Source\Services\Help\PageGuides::for($currentPageId ?? null);
if ($introGuide === null) {
    return;
}
$introRenderer = new \Source\Services\Help\ArticleRenderer(
    static function (string $slug): ?string {
        $file = glob(\Source\Services\Help\KnowledgeBase::defaultRoot() . '/artigos/*/' . basename($slug) . '.md')[0] ?? null;
        return $file === null ? null : \Source\Services\Environments::url('site', '/suporte/' . basename(dirname($file)) . '/' . basename($file, '.md'));
    }
);
$introCode = (string)$currentPageId;
$introHelp = \Source\Services\Environments::url('site', '/suporte/tela/' . rawurlencode($introCode));
$introItems = array_slice($introGuide['por_tras'], 0, 3);
?>
<details class="erp-page-intro" open data-erp-intro="<?= $this->e($introCode) ?>">
    <summary class="erp-page-intro__summary"><?= $icon('help', 'erp-icon--sm') ?><span>Sobre esta tela</span></summary>
    <div class="erp-page-intro__body">
        <p><?= $introRenderer->inline($introGuide['faz']) ?></p>
        <?php if ($introItems !== []): ?>
            <ol class="erp-page-intro__list">
                <?php foreach ($introItems as $introItem): ?><li><?= $introRenderer->inline($introItem) ?></li><?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <a class="erp-page-intro__link" href="<?= $this->e($introHelp) ?>" target="_blank" rel="noopener">Abrir tutorial completo <span aria-hidden="true">→</span></a>
    </div>
</details>
