<?php
/*
 * Depoimentos só reais, cadastrados no Studio e publicados (status published), com autorização do cliente.
 * Sem depoimento, a seção não aparece: não há mais depoimentos de exemplo (CDC arts. 37 e 67; ADR 0600).
 */
$briefs = is_array($briefs ?? null) ? $briefs : [];
?>

<?php if (!empty($briefs)): ?>
    <section class="section section--soft" id="sucesso">
        <div class="container">
            <header class="section__head reveal">
                <span class="eyebrow">O QUE NOSSOS CLIENTES DIZEM</span>
                <h2>Experiências que <em>geram confiança.</em></h2>
            </header>

            <div class="cards cards--testimonials">
                <?php foreach ($briefs as $brief): ?>
                    <article>
                        <div class="testimonial-card__header">
                            <?php if (!empty($brief->cover)): ?>
                                <img class="testimonial-card__photo"
                                     src="<?= image($brief->cover, 120, 120); ?>"
                                     alt="<?= $brief->title; ?>"/>
                            <?php endif; ?>
                            <div>
                                <?php $rating=min(5,max(1,(int)($brief->rating??5))); ?><div class="stars" aria-label="<?= $rating ?> de 5 estrelas"><?= str_repeat('★',$rating) ?><span><?= str_repeat('☆',5-$rating) ?></span></div>
                                <b><?= $brief->title; ?></b>
                                <?php if (!empty($brief->townhouse)): ?>
                                    <small><?= $brief->townhouse; ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p>“<?= trim(strip_tags(html_entity_decode($brief->content))); ?>”</p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
