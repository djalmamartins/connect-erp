<?php

use MovesCode\Compress\CSS;
use MovesCode\Compress\JS;

// A compilação é uma etapa explícita de build. Nunca escreva assets durante
// uma requisição web, pois o usuário do servidor pode não ter permissão.
if ((getenv("MOVESOS_ENV") ?: "") === "testing" || getenv("MOVESOS_BUILD_ASSETS") !== "1") return;

$movesRoot = dirname(__DIR__, 3);
$themeRoot = moves_container_path("studio", "default");

$css = new CSS();
// O Studio possui identidade e componentes próprios. Do Organic preservamos
// exclusivamente o CSS do editor de conteúdo.
foreach ([$themeRoot . "/assets/vendor/organic-editor/organic-editor.min.css", $themeRoot . "/assets/css/studio-icons.css", $themeRoot . "/assets/css/admin.css", $themeRoot . "/assets/css/studio-system.css"] as $asset) {
    if (is_file($asset)) $css->add($asset);
}
$cssTarget = $themeRoot . "/assets/studio.min.css";
$css->minify($cssTarget);
chmod($cssTarget, 0664);

$js = new JS();
foreach ([$movesRoot . "/container/shared/assets/vendor/scripts/jquery.min.js", $movesRoot . "/container/shared/assets/vendor/scripts/jquery.form.js", $movesRoot . "/container/shared/assets/vendor/scripts/jquery-ui.js", $themeRoot . "/assets/js/scripts.js"] as $asset) {
    if (is_file($asset)) $js->add($asset);
}
$jsTarget = $themeRoot . "/assets/studio.min.js";
$js->minify($jsTarget);
chmod($jsTarget, 0664);
