<?php

/**
 * ERP v1 — publica o design system do tema (assets/css/erp.css e assets/js/erp.js) em
 * assets/erp.min.css e assets/erp.min.js com o MovesCode Compress.
 *
 * Diferente do bundle legado, não inclui jQuery, Highcharts nem plugins: o ERP v1 não usa
 * dependências de frontend. Só reconstrói em ambiente local quando um arquivo-fonte é mais
 * novo que o bundle, para não reescrever arquivos a cada requisição.
 */

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;

if ((getenv("MOVESOS_ENV") ?: "") === "testing" || !defined("CONF_VIEW_ERP") || !function_exists("moves_container_path")) {
    return;
}

$erpThemePath = moves_container_path('erp', CONF_VIEW_ERP);
$erpBundles = [
    $erpThemePath . "/assets/erp.min.css" => [CSS::class, glob($erpThemePath . "/assets/css/*.css") ?: []],
    $erpThemePath . "/assets/erp.min.js" => [JS::class, glob($erpThemePath . "/assets/js/*.js") ?: []],
];

if (is_local_host() && is_dir($erpThemePath . "/assets") && is_writable($erpThemePath . "/assets")) {
    foreach ($erpBundles as $erpTarget => [$erpCompressor, $erpSources]) {
        if ($erpSources === []) {
            continue;
        }
        sort($erpSources);
        $erpNewest = max(array_map('filemtime', $erpSources));
        if (is_file($erpTarget) && filemtime($erpTarget) >= $erpNewest) {
            continue;
        }
        (new $erpCompressor(...$erpSources))->minify($erpTarget);
        @chmod($erpTarget, 0664);
    }
}
unset($erpThemePath, $erpBundles, $erpTarget, $erpCompressor, $erpSources, $erpNewest);
