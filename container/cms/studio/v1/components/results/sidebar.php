<div class="dash_content_sidebar">
    <h3 class="icon-asterisk">dashboard/resultados</h3>
    <p class="dash_content_sidebar_desc">Gerencie, monitore e acompanhe os resultados...</p>

    <nav>
        <?php
        $nav = function ($icon, $href, $title) use ($app) {
            $active = ($app == $href ? "active" : null);
            $url = url("/studio/{$href}");
            return "<a class=\"icon-{$icon} radius {$active}\" href=\"{$url}\">{$title}</a>";
        };

        echo $nav("trophy", "results/home", "Resultados");
        echo $nav("bookmark", "results/categories", "Categorias");
        echo $nav("plus-circle", "results/create", "Importar Arquivos");
        ?>
    </nav>
</div>
