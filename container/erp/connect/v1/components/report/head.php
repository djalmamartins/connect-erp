<?php
/**
 * Cabeçalho do relatório (base única de impressão, ADR 0635): a faixa verde-escura com a borda dourada e o
 * logo da Connect (o mesmo modelo do Livro caixa, ADR 0174), o grupo e o título da tela e, embaixo, o
 * condomínio da barra superior, quando e por quem foi emitido. Os filtros da tela entram em
 * [data-erp-report-params] pelo erp.js (De, Até, Unidade...), como linha de parâmetros.
 * @var string $reportTitle @var ?string $reportEyebrow @var ?string $reportLead
 */
use Source\Support\Erp\ReportSheet;

$reportCondo = \Source\Support\Erp\CondominiumContext::current();
$reportLogo = function_exists('site_logo_url') ? site_logo_url() : theme('/assets/images/logo-connect-condominios.svg');
$reportIssuer = ReportSheet::issuer($user ?? null);
?>
<header class="erp-report-head">
    <div class="erp-report-band">
        <img class="erp-report-band__logo" src="<?= $this->e($reportLogo) ?>" alt="Connect Condomínios" width="152" height="40">
        <div class="erp-report-band__title">
            <?php if (!empty($reportEyebrow)): ?><span><?= $this->e($reportEyebrow) ?></span><?php endif; ?>
            <h1><?= $this->e($reportTitle) ?></h1>
        </div>
    </div>
    <dl class="erp-report-meta">
        <div class="erp-report-meta__wide"><dt>Condomínio</dt><dd><?= $this->e($reportCondo !== null ? (string)$reportCondo['name'] : 'Todos os condomínios do seu acesso') ?></dd></div>
        <div><dt>Emitido em</dt><dd><?= $this->e(ReportSheet::issuedAt()) ?></dd></div>
        <?php if ($reportIssuer !== ''): ?><div><dt>Emitido por</dt><dd><?= $this->e($reportIssuer) ?></dd></div><?php endif; ?>
    </dl>
    <dl class="erp-report-meta erp-report-meta--params" data-erp-report-params hidden></dl>
    <?php if (!empty($reportLead)): ?><p class="erp-report-lead"><?= $this->e($reportLead) ?></p><?php endif; ?>
</header>
