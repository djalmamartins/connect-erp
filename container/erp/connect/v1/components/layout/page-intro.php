<?php
/**
 * "Sobre esta tela" (ADR 0309): o que a página faz e o principal do que acontece por trás, com o link
 * para o tutorial completo na Central de Ajuda. Texto de Source\Services\Help\PageGuides (a mesma
 * fonte da seção "O que acontece por trás" do artigo).
 *
 * Em todas as páginas do ERP (ADR 0625): a tela sem explicação escrita à mão mostra o resumo do artigo
 * (source/Services/Help/page-summaries.php, gerado por `php tools/help.php resumos`). Também lista as
 * seções do tutorial, cada uma com link direto.
 *
 * Visual (retornos do Djalma, 15–20/11/2026): SEMPRE nasce fechada (só o título) e a pessoa abre se
 * tiver dúvida; caixa verde-clara de borda fina, título pequeno com ícone, texto curto, até 3 itens e
 * "Abrir tutorial completo". O X fecha e ela não volta mais naquela tela, neste navegador (erp.js,
 * localStorage). Em Configurações > Administrativo, "Mostrar o quadro Sobre esta tela"
 * (admin.page_intro) desliga para todos.
 *
 * @var ?string $currentPageId
 */
$introGuide = \Source\Services\Help\PageGuides::forPage($currentPageId ?? null);
if ($introGuide === null) {
    return;
}
try {
    if (!\Source\Services\Erp\ErpSettings::value(\Source\Core\Connect::getInstance(), null, 'admin.page_intro')) {
        return;
    }
} catch (\Throwable) {
    // Sem banco (smoke de renderização) vale o padrão: mostrar.
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
<div class="erp-page-intro" data-erp-intro="<?= $this->e($introCode) ?>">
    <details class="erp-page-intro__box">
        <summary class="erp-page-intro__summary"><?= $icon('help', 'erp-icon--sm') ?><span>Sobre esta tela</span></summary>
        <div class="erp-page-intro__body">
            <p><?= $introRenderer->inline($introGuide['faz']) ?></p>
            <?php if ($introItems !== []): ?>
                <ol class="erp-page-intro__list">
                    <?php foreach ($introItems as $introItem): ?><li><?= $introRenderer->inline($introItem) ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($introGuide['topicos'] !== []): ?>
                <p class="erp-page-intro__topics"><span>No tutorial:</span>
                    <?php foreach ($introGuide['topicos'] as $introIndex => $introTopic): ?><?= $introIndex > 0 ? '<span aria-hidden="true">·</span>' : '' ?><a href="<?= $this->e($introHelp . '#' . $introTopic['id']) ?>" target="_blank" rel="noopener"><?= $introRenderer->inline($introTopic['title']) ?></a><?php endforeach; ?>
                </p>
            <?php endif; ?>
            <a class="erp-page-intro__link" href="<?= $this->e($introHelp) ?>" target="_blank" rel="noopener">Abrir tutorial completo <span aria-hidden="true">→</span></a>
        </div>
    </details>
    <button type="button" class="erp-page-intro__close" data-erp-intro-close aria-label="Fechar e não mostrar mais nesta tela" title="Fechar e não mostrar mais nesta tela"><?= $icon('close', 'erp-icon--sm') ?></button>
</div>
