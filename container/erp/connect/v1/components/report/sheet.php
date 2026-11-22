<?php
/**
 * Folha do relatório (base única de impressão, ADR 0635): a moldura do ERP no modo ?imprimir=1. Sem menu
 * nem barra superior; a barra de cima (Voltar e "Imprimir ou salvar em PDF") some na impressão. A folha
 * tem o tamanho de uma A4 na tela, para a pessoa ver o PDF antes de imprimir. O cabeçalho vem do
 * page-head da própria tela (components/report/head); o rodapé fecha com a Connect e o aviso de conferência.
 * @var string $reportContent HTML da seção 'content' da tela
 */
$reportSite = defined('CONF_SITE_NAME') && CONF_SITE_NAME !== '' ? CONF_SITE_NAME : 'Connect Condomínios';
?>
<div class="erp-report-toolbar" role="toolbar" aria-label="Relatório para imprimir">
    <button type="button" class="erp-btn" data-erp-report-close><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar</button>
    <span class="erp-report-toolbar__hint">Prévia do relatório. Para gerar o PDF, escolha "Salvar como PDF" no destino da impressão.</span>
    <button type="button" class="erp-btn erp-btn--primary" data-erp-report-print><?= $icon('printer', 'erp-icon--sm') ?>Imprimir ou salvar em PDF</button>
</div>
<main id="erp-main" class="erp-main erp-report-sheet" tabindex="-1">
    <?= $reportContent ?>
    <footer class="erp-report-foot">
        <span><?= $this->e($reportSite) ?> · administradora de condomínios</span>
        <span>Relatório gerado pelo Connect ERP com os dados do momento da emissão.</span>
    </footer>
</main>
