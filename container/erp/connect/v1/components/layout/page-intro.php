<?php
/**
 * "Sobre esta tela" (ADR 0309): o que a página faz e o que acontece por trás quando você grava,
 * para quem usa o sistema pela primeira vez. Texto de Source\Services\Help\PageGuides (a mesma
 * fonte da seção "O que acontece por trás" do artigo da Central de Ajuda). Quem fecha o quadro
 * deixa ele fechado nesta página (erp.js guarda no navegador); "Mostrar" abre de novo.
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
?>
<details class="erp-page-intro" open data-erp-intro="<?= $this->e($introCode) ?>">
    <summary class="erp-page-intro__summary">
        <span class="erp-page-intro__icon" aria-hidden="true">i</span>
        <span class="erp-page-intro__title">Sobre esta tela</span>
        <span class="erp-page-intro__toggle" data-open="Esconder" data-closed="Mostrar" aria-hidden="true"></span>
    </summary>
    <div class="erp-page-intro__body">
        <p class="erp-page-intro__lead"><?= $introRenderer->inline($introGuide['faz']) ?></p>
        <?php if ($introGuide['por_tras'] !== []): ?>
            <h2 class="erp-page-intro__heading">O que acontece por trás</h2>
            <ul class="erp-page-intro__list">
                <?php foreach ($introGuide['por_tras'] as $introItem): ?><li><?= $introRenderer->inline($introItem) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="erp-page-intro__more" href="<?= $this->e($introHelp) ?>" target="_blank" rel="noopener">Guia completo desta tela, com as telas marcadas</a>
    </div>
</details>
