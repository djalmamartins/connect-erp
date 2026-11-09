<?php $this->layout("layouts/error"); ?>
<?php /** Verificação pública de documento (ADR 0098). Só mostra autêntico, tipo, data de emissão e condomínio. */ ?>
<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1>Verificar documento</h1>
            </header>
            <div class="main_login_form">
                <article>
                    <?php if ($result !== null): ?>
                        <div class="ajax_response" role="status">
                            <?php if ($result['ok'] && is_array($result['document'])): ?>
                                <p><strong><?= $this->e($result['message']); ?></strong></p>
                                <p>Tipo: <?= $this->e($result['document']['kind']); ?><br>
                                    Emitido em: <?= $this->e($result['document']['issued_on']); ?><br>
                                    Condomínio: <?= $this->e($result['document']['condominium']); ?></p>
                            <?php else: ?>
                                <p><?= $this->e($result['message']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <form action="<?= url("/verificar"); ?>" method="post" autocomplete="off">
                        <?= csrf_input(); ?>
                        <label>
                            <div><span class="icon-check-square-o">Código de verificação do documento:</span></div>
                            <input type="text" name="code" maxlength="24" placeholder="XXXX-XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false" required/>
                        </label>
                        <button class="btn btn-full gradient gradient-web gradient-hover transition icon-check-square-o">Verificar</button>
                    </form>
                </article>
            </div>
        </div>
    </div>
</article>
