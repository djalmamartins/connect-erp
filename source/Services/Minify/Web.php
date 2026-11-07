<?php

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;
$movesRoot = dirname(__DIR__, 3);
if ((getenv("MOVESOS_ENV") ?: "") === "testing") {
    return;
}

if (is_local_host()) {
    $webThemePath = moves_container_path('web', CONF_VIEW_THEME);
    /**
     * CSS
     */
    $minCSS = new CSS();
    //theme CSS
    $cssDir = scandir($webThemePath . "/assets/css");
    foreach ($cssDir as $css) {
        $cssFile = $webThemePath . "/assets/css/{$css}";
        if (is_file($cssFile) && pathinfo($cssFile)['extension'] == "css") {
            $minCSS->add($cssFile);
        }
    }

    //Minify CSS
    $webStyleTarget = $webThemePath . "/assets/style.css";
    $minCSS->minify($webStyleTarget);
    chmod($webStyleTarget, 0664);

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
    $jsDir = scandir($webThemePath . "/assets/js");
    foreach ($jsDir as $js) {
        $jsFile = $webThemePath . "/assets/js/{$js}";
        if (is_file($jsFile) && pathinfo($jsFile)['extension'] == "js") {
            $minJS->add($jsFile);
        }
    }

    //Minify JS
    $webScriptTarget = $webThemePath . "/assets/scripts.js";
    $minJS->minify($webScriptTarget);
    chmod($webScriptTarget, 0664);
}
