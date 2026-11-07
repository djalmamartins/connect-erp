<?php

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;
$movesRoot = dirname(__DIR__, 3);
if ((getenv("MOVESOS_ENV") ?: "") === "testing") {
    return;
}

if (is_local_host()) {
    $webThemePath = moves_container_path('web', CONF_VIEW_THEME);
    $needsBuild = static function (string $target, array $sources): bool {
        if (!is_file($target) || filesize($target) === 0) {
            return true;
        }
        $targetTime = filemtime($target) ?: 0;
        foreach ($sources as $source) {
            if (!is_file($source)) {
                throw new RuntimeException("Asset obrigatório não encontrado: {$source}");
            }
            if ((filemtime($source) ?: 0) > $targetTime) {
                return true;
            }
        }
        return false;
    };

    /**
     * CSS
     */
    $cssSources = [
        $webThemePath . "/assets/css/style.css",
        $webThemePath . "/assets/css/message.css",
        $webThemePath . "/assets/messages.css",
        $webThemePath . "/assets/responsive.css",
        $webThemePath . "/assets/pages.css",
        $webThemePath . "/assets/motion.css",
    ];
    $webStyleTarget = $webThemePath . "/assets/site.min.css";
    if ($needsBuild($webStyleTarget, $cssSources)) {
        $minCSS = new CSS();
        foreach ($cssSources as $cssFile) {
            $minCSS->add($cssFile);
        }
        $minCSS->minify($webStyleTarget);
        chmod($webStyleTarget, 0664);
    }

    /**
     * JS
     */
    $jsSources = [
        $movesRoot . "/container/shared/assets/vendor/scripts/jquery.min.js",
        $movesRoot . "/container/shared/assets/vendor/owl/owl.carousel.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/jquery.form.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/jquery-ui.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/jquery.mask.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/highcharts.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/tracker.js",
        $movesRoot . "/container/shared/assets/vendor/scripts/validation.js",
    ];
    foreach (scandir($webThemePath . "/assets/js") as $js) {
        $jsFile = $webThemePath . "/assets/js/{$js}";
        if (is_file($jsFile) && pathinfo($jsFile, PATHINFO_EXTENSION) === "js") {
            $jsSources[] = $jsFile;
        }
    }
    $webScriptTarget = $webThemePath . "/assets/site.min.js";
    if ($needsBuild($webScriptTarget, $jsSources)) {
        $minJS = new JS();
        foreach ($jsSources as $jsFile) {
            $minJS->add($jsFile);
        }
        $minJS->minify($webScriptTarget);
        chmod($webScriptTarget, 0664);
    }
}
