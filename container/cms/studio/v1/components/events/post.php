<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/events/sidebar.php"); ?>

<div class="mce_upload" style="z-index: 997">
    <div class="mce_upload_box">
        <form class="app_form" action="<?= url("/studio/events/post"); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="upload" value="true"/>
            <label>
                <label class="legend">Selecione uma imagem JPG ou PNG:</label>
                <input accept="image/*" type="file" name="image" required/>
            </label>
            <button class="btn btn-blue icon-upload">Enviar Imagem</button>
        </form>
    </div>
</div>

<section class="dash_content_app">
    <?php if (!$post): ?>
        <header class="dash_content_app_header">
            <h2 class="icon-calendar-plus-o">Novo Evento</h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/events/post"); ?>" method="post">

                <div class="flex">
                    <div class="two_box">
                        <input type="hidden" name="action" value="create"/>

                        <label class="label">
                            <span class="legend">*Título do evento:</span>
                            <input type="text" name="title" maxlength="140" placeholder="Nome do evento" required/>
                        </label>
                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Data do início:</span>
                                <input type="datetime-local" name="start_at" value="<?= date_fmt_local(date("Y/m/d H:i"))?>" required/>
                            </label>

                            <label class="label">
                                <span class="legend">Data do fim:</span>
                                <input type="datetime-local" name="end_at"/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Cidade - Estado:</span>
                                <input type="text" name="city" placeholder="Cidade sede" required/>
                            </label>

                            <label class="label">
                                <span class="legend">*Local:</span>
                                <input type="text" name="local" placeholder="Local de realização" required/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">Promotora:</span>
                                <input type="text" name="promoter" placeholder="Promotora"/>
                            </label>

                            <label class="label">
                                <span class="legend">Organizadora:</span>
                                <input type="text" name="organizer" placeholder="Organizadora"/>
                            </label>
                        </div>
                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Contato:</span>
                                <input type="text" name="contact" placeholder="Nome do contato" required/>
                            </label>

                            <label class="label">
                                <span class="legend">*Telefone:</span>
                                <input type="text" name="phone" placeholder="Telefone" required/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">E-mail:</span>
                                <input type="text" name="mail" placeholder="E-mail"/>
                            </label>

                            <label class="label">
                                <span class="legend">Site:</span>
                                <input type="text" name="site" placeholder="Site"/>
                            </label>
                        </div>

                        <label class="label">
                            <span class="legend">Conteúdo:</span>
                            <textarea class="mce" name="content"></textarea>
                        </label>

                    </div>
                    <div class="two_box_sidebar">
                        <label class="label">
                            <span class="legend">Capa: (1920x1080px)</span>
                            <input type="file" name="cover" placeholder="Uma imagem de capa"/>
                        </label>

                        <label class="label">
                            <span class="legend">Vídeo:</span>
                            <input type="text" name="video" placeholder="O ID de um vídeo do YouTube"/>
                        </label>
                        <label class="label">
                            <span class="legend">*Categoria:</span>
                            <select name="category" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category->id; ?>"><?= $category->title; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="label">
                            <span class="legend">*Autor:</span>
                            <select name="author" required>
                                <?php foreach ($authors as $author): ?>
                                    <option value="<?= $author->id; ?>"><?= $author->fullName(); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="label">
                            <span class="legend">*Status:</span>
                            <select name="status" required>
                                <option value="post">Publicar</option>
                                <option value="draft">Rascunho</option>
                                <option value="trash">Lixo</option>
                            </select>
                        </label>

                        <label class="label">
                            <span class="legend">Data de publicação:</span>
                            <input type="datetime-local" name="post_at" value="<?= date_fmt_local(date("Y/m/d H:i"))?>" required/>
                        </label>
                        <div class="al-right">
                            <button class="btn btn-green icon-check-square-o">Publicar</button>
                        </div>

                    </div>
                </div>

            </form>
        </div>
    <?php else: ?>
        <header class="dash_content_app_header">
            <h2 class="icon-calendar">Editar evento #<?= $post->id; ?></h2>
            <a class="icon-link btn btn-green" href="<?= url("/events/{$post->uri}"); ?>" target="_blank" title="">Ver no
                site</a>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/events/post/{$post->id}"); ?>" method="post">

                <div class="flex">
                    <div class="two_box">
                        <!-- ACTION SPOOFING-->
                        <input type="hidden" name="action" value="update"/>

                        <label class="label">
                            <span class="legend">*Título:</span>
                            <input type="text" name="title" value="<?= $post->title; ?>" placeholder="A manchete do seu artigo"
                                   required/>
                        </label>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Data do início:</span>
                                <input type="datetime-local" name="start_at" value="<?= date_fmt_local($post->start_at)?>" required/>
                            </label>

                            <label class="label">
                                <span class="legend">Data do fim:</span>
                                <input type="datetime-local" name="end_at" value="<?= date_fmt_local($post->end_at)?>"/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Cidade - Estado:</span>
                                <input type="text" name="city" value="<?= $post->city; ?>" required/>
                            </label>

                            <label class="label">
                                <span class="legend">*Local:</span>
                                <input type="text" name="local" value="<?= $post->local; ?>" required/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">Promotora:</span>
                                <input type="text" name="promoter" value="<?= $post->promoter; ?>"/>
                            </label>

                            <label class="label">
                                <span class="legend">Organizadora:</span>
                                <input type="text" name="organizer" value="<?= $post->organizer; ?>"/>
                            </label>
                        </div>
                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">*Contato:</span>
                                <input type="text" name="contact" value="<?= $post->contact; ?>" required/>
                            </label>

                            <label class="label">
                                <span class="legend">*Telefone:</span>
                                <input type="text" name="phone" value="<?= $post->phone; ?>" required/>
                            </label>
                        </div>

                        <div class="label_g2">
                            <label class="label">
                                <span class="legend">E-mail:</span>
                                <input type="text" name="mail" value="<?= $post->mail; ?>"/>
                            </label>

                            <label class="label">
                                <span class="legend">Site:</span>
                                <input type="text" name="site" value="<?= $post->site; ?>"/>
                            </label>
                        </div>

                        <label class="label">
                            <span class="legend">Conteúdo:</span>
                            <textarea class="mce" name="content"><?= $post->content; ?></textarea>
                        </label>


                    </div>
                    <div class="two_box_sidebar">

                        <label class="label">
                            <span class="legend">Capa: (1920x1080px)</span>
                            <input type="file" name="cover" placeholder="Uma imagem de capa"/>
                        </label>

                        <label class="label">
                            <span class="legend">Vídeo:</span>
                            <input type="text" name="video" value="<?= $post->video; ?>"
                                   placeholder="O ID de um vídeo do YouTube"/>
                        </label>

                        <label class="label">
                            <span class="legend">*Categoria:</span>
                            <select name="category" required>
                                <?php foreach ($categories as $category):
                                    $categoryId = $post->category;
                                    $select = function ($value) use ($categoryId) {
                                        return ($categoryId == $value ? "selected" : "");
                                    };
                                    ?>
                                    <option <?= $select($category->id); ?>
                                            value="<?= $category->id; ?>"><?= $category->title; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="label">
                            <span class="legend">*Autor:</span>
                            <select name="author" required>
                                <?php foreach ($authors as $author):
                                    $authorId = $post->author;
                                    $select = function ($value) use ($authorId) {
                                        return ($authorId == $value ? "selected" : "");
                                    };
                                    ?>
                                    <option <?= $select($author->id); ?>
                                            value="<?= $author->id; ?>"><?= $author->fullName(); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="label">
                            <span class="legend">*Status:</span>
                            <select name="status" required>
                                <?php
                                $status = $post->status;
                                $select = function ($value) use ($status) {
                                    return ($status == $value ? "selected" : "");
                                };
                                ?>
                                <option <?= $select("post"); ?> value="post">Publicar</option>
                                <option <?= $select("draft"); ?> value="draft">Rascunho</option>
                                <option <?= $select("trash"); ?> value="trash">Lixo</option>
                            </select>
                        </label>

                        <label class="label">
                            <span class="legend">Data de publicação:</span>
                            <input type="datetime-local" name="post_at"
                                   value="<?= date_fmt_local($post->post_at)?>" required/>
                        </label>
                        <div class="al-right">
                            <button class="btn btn-blue icon-pencil-square-o">Atualizar</button>
                        </div>
                    </div>
                </div>



            </form>
        </div>
    <?php endif; ?>
</section>