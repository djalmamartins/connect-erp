<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/results/sidebar.php"); ?>

<section class="dash_content_app">

    <header class="dash_content_app_header">
        <h2 class="icon-plus-circle">Importar Arquivos</h2>
    </header>

    <div class="dash_content_app_box">
        <form accept-charset="utf-8" class="app_form" action="<?= url("/studio/results/create"); ?>" method="post">
            <!--ACTION SPOOFING-->
            <input type="hidden" name="action" value="create"/>

                <label class="label">
                    <span class="legend">Selecione o arquivo concurc.txt</span>
                    <input type="file" name="concurc"/>
                </label>

            <div class="label_g2">
                <label class="label">
                    <span class="legend">Selecione o arquivo pc22.txt</span>
                    <input type="file" name="pc22"/>
                </label>
                <label class="label">
                    <span class="legend">Selecione o arquivo pc24.txt</span>
                    <input type="file" name="pc24"/>
                </label>
            </div>
            <div class="label_g2">
                <label class="label">
                    <span class="legend">Selecione o arquivo tc32.txt</span>
                    <input type="file" name="tc32"/>
                </label>
                <label class="label">
                    <span class="legend">Selecione o arquivo tc33.txt</span>
                    <input type="file" name="tc33"/>
                </label>
            </div>
            <label class="label">
                <span class="legend"></span>
                <button class="btn btn-green icon-check-square-o">Cadastrar</button>
            </label>
        </form>
    </div>
</section>
