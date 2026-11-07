<?php

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;
$movesRoot = dirname(__DIR__, 3);
if ((getenv("MOVESOS_ENV") ?: "") === "testing") {
    return;
}

$appThemePath = moves_container_path('residents', CONF_VIEW_APP);
$appStyleTarget = $appThemePath . "/assets/style.css";
$appScriptTarget = $appThemePath . "/assets/scripts.js";
$appAssetsWritable = (is_file($appStyleTarget) ? is_writable($appStyleTarget) : is_writable($appThemePath . "/assets"))
    && (is_file($appScriptTarget) ? is_writable($appScriptTarget) : is_writable($appThemePath . "/assets"));

if (is_local_host()
    && is_dir($appThemePath . "/assets/css")
    && is_dir($appThemePath . "/assets/js")
    && $appAssetsWritable) {
    /**
     * CSS
     */
    $minCSS = new CSS();
    $minCSS->add($movesRoot . "/container/shared/assets/vendor/owl/owl.carousel.min.css");
    $minCSS->add($movesRoot . "/container/shared/assets/vendor/owl/owl.theme.default.min.css");
    $minCSS->add($movesRoot . "/organic/organic.min.css");
    $minCSS->add($movesRoot . "/organic/compat-v1.css");

    //theme CSS
    $cssDir = scandir($appThemePath . "/assets/css");
    foreach ($cssDir as $css) {
        $cssFile = $appThemePath . "/assets/css/{$css}";
        if (is_file($cssFile) && pathinfo($cssFile)['extension'] == "css") {
            $minCSS->add($cssFile);
        }
    }

    //Minify CSS
    $minCSS->minify($appStyleTarget);
    chmod($appStyleTarget, 0664);

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
    $jsDir = scandir($appThemePath . "/assets/js");
    foreach ($jsDir as $js) {
        $jsFile = $appThemePath . "/assets/js/{$js}";
        if (is_file($jsFile) && pathinfo($jsFile)['extension'] == "js") {
            $minJS->add($jsFile);
        }
    }

    //Minify JS
    $minJS->minify($appScriptTarget);
    chmod($appScriptTarget, 0664);
}
