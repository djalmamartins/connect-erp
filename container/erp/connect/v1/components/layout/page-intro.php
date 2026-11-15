<?php
/**
 * "Sobre esta tela" (ADR 0309): o que a página faz e, recolhido, o que acontece por trás quando você
 * grava, para quem usa o sistema pela primeira vez. Texto de Source\Services\Help\PageGuides (a mesma
 * fonte da seção "O que acontece por trás" do artigo da Central de Ajuda).
 *
 * Visual discreto (retorno do Djalma, 15/11/2026): uma linha clara, sem borda grossa nem negrito. O X
 * fecha o quadro e ele não volta mais naquela tela, neste navegador (erp.js guarda no localStorage).
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
<aside class="erp-page-intro" data-erp-intro="<?= $this->e($introCode) ?>" aria-label="Sobre esta tela">
    <span class="erp-page-intro__icon" aria-hidden="true"><?= $icon('info', 'erp-icon--sm') ?></span>
    <div class="erp-page-intro__body">
        <p class="erp-page-intro__lead"><?= $introRenderer->inline($introGuide['faz']) ?></p>
        <?php if ($introGuide['por_tras'] !== []): ?>
            <details class="erp-page-intro__more">
                <summary>O que acontece por trás</summary>
                <ul class="erp-page-intro__list">
                    <?php foreach ($introGuide['por_tras'] as $introItem): ?><li><?= $introRenderer->inline($introItem) ?></li><?php endforeach; ?>
                </ul>
                <a class="erp-page-intro__link" href="<?= $this->e($introHelp) ?>" target="_blank" rel="noopener">Guia completo desta tela</a>
            </details>
        <?php endif; ?>
    </div>
    <button type="button" class="erp-page-intro__close" data-erp-intro-close aria-label="Fechar e não mostrar mais nesta tela" title="Fechar e não mostrar mais nesta tela"><?= $icon('close', 'erp-icon--sm') ?></button>
</aside>
