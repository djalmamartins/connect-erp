<div class="dash_content_sidebar">
    <h3 class="icon-asterisk">dashboard/associations</h3>
    <p class="dash_content_sidebar_desc">Gerencie, monitore e acompanhe os clubes do seu site aqui...</p>

    <nav>
        <?php
        $nav = function ($icon, $href, $title) use ($app) {
            $active = ($app == $href ? "active" : null);
            $url = url("/studio/{$href}");
            return "<a class=\"icon-{$icon} radius {$active}\" href=\"{$url}\">{$title}</a>";
        };

        echo $nav("university", "associations/home", "Associalções");
        echo $nav("plus-circle", "associations/association", "Nova Associação");
        echo $nav("print", "associations/print", "Imprimir");
        ?>
    </nav>
</div>
