<?php

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;
$movesRoot = dirname(__DIR__, 3);
if ((getenv("MOVESOS_ENV") ?: "") === "testing") {
    return;
}

$erpThemePath = moves_container_path('erp', CONF_VIEW_ERP);
$erpStyleTarget = $erpThemePath . "/assets/style.css";
$erpScriptTarget = $erpThemePath . "/assets/scripts.js";
$erpAssetsWritable = (is_file($erpStyleTarget) ? is_writable($erpStyleTarget) : is_writable($erpThemePath . "/assets"))
    && (is_file($erpScriptTarget) ? is_writable($erpScriptTarget) : is_writable($erpThemePath . "/assets"));

if (is_local_host()
    && is_dir($erpThemePath . "/assets/css")
    && is_dir($erpThemePath . "/assets/js")
    && $erpAssetsWritable) {
    /**
     * CSS
     */
    $minCSS = new CSS();
    $minCSS->add($movesRoot . "/container/shared/assets/vendor/owl/owl.carousel.min.css");
    $minCSS->add($movesRoot . "/container/shared/assets/vendor/owl/owl.theme.default.min.css");
    $minCSS->add($movesRoot . "/organic/organic.min.css");
    $minCSS->add($movesRoot . "/organic/compat-v1.css");

    //theme CSS
    $cssDir = scandir($erpThemePath . "/assets/css");
    foreach ($cssDir as $css) {
        $cssFile = $erpThemePath . "/assets/css/{$css}";
        if (is_file($cssFile) && pathinfo($cssFile)['extension'] == "css") {
            $minCSS->add($cssFile);
        }
    }

    //Minify CSS
    $minCSS->minify($erpStyleTarget);
    chmod($erpStyleTarget, 0664);

    /**
     * JS
     */
    $minJS = new JS();
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/jquery.min.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/owl/owl.carousel.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/jquery.form.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/jquery-ui.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/jquery.mask.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/highcharts.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/tracker.js");
    $minJS->add($movesRoot . "/container/shared/assets/vendor/scripts/validation.js");

    //theme CSS
    $jsDir = scandir($erpThemePath . "/assets/js");
    foreach ($jsDir as $js) {
        $jsFile = $erpThemePath . "/assets/js/{$js}";
        if (is_file($jsFile) && pathinfo($jsFile)['extension'] == "js") {
            $minJS->add($jsFile);
        }
    }

    //Minify JS
    $minJS->minify($erpScriptTarget);
    chmod($erpScriptTarget, 0664);
}
