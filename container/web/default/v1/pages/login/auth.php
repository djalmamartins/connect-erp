<?php $this->layout("layouts/error"); ?>

<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1></h1>
            </header>
            <div class="main_login_form">
                <div class="list">
                    <a href="<?= url("/app") ?>">
                        <div class="app_icon">
                            <img src="<?= theme("/assets/svg/app.svg"); ?>" alt="App Morador">
                        </div>

                        <h3>App Morador</h3>
                    </a>
                </div>

                <div class="list">
                    <a href="<?= url("/sin") ?>">
                        <div class="app_icon">
                            <img src="<?= theme("/assets/svg/sin.svg"); ?>" alt="App Síndico">
                        </div>

                        <h3>App Síndico</h3>
                    </a>
                </div>

                <div class="list">
                    <a href="<?= url("/erp") ?>">
                        <div class="app_icon">
                            <img src="<?= theme("/assets/svg/erp.svg"); ?>" alt="ERP">
                        </div>

                        <h3>ERP</h3>
                    </a>
                </div>

                <div class="list">
                    <a href="<?= url("/Studio") ?>">
                        <div class="app_icon">
                            <img src="<?= theme("/assets/svg/studio.svg"); ?>" alt="Studio">
                        </div>

                        <h3>Studio</h3>
                    </a>
                </div>
            </div>
        </div>
    </div>
</article>
