<div class="dash_content_sidebar">
    <h3 class="icon-asterisk">dashboard/eventos</h3>
    <p class="dash_content_sidebar_desc">Aqui você gerencia todos os trabalho e categorias do eventos...</p>

    <nav>
        <?php
        $nav = function ($icon, $href, $title) use ($app) {
            $active = ($app == $href ? "active" : null);
            $url = url("/studio/{$href}");
            return "<a class=\"icon-{$icon} radius {$active}\" href=\"{$url}\">{$title}</a>";
        };

        echo $nav("calendar-o", "events/home", "Eventos");
        echo $nav("bookmark", "events/categories", "Categorias");
        echo $nav("calendar-plus-o", "events/post", "Novo Evento");
        ?>

        <?php if (!empty($post->cover)): ?>
            <img class="radius" style="width: 100%; margin-top: 30px" src="<?= image($post->cover, 680); ?>"/>
        <?php endif; ?>

        <?php if (!empty($category->cover)): ?>
            <img class="radius" style="width: 100%; margin-top: 30px" src="<?= image($category->cover, 680); ?>"/>
        <?php endif; ?>
    </nav>
</div>
