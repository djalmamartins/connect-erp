<?php $this->layout("layouts/error"); ?>






<!-- =========================================================
     PÁGINA DE ERRO
========================================================= -->

<main class="error-page">

    <div class="error-container">


        <!-- =================================================
             LOGO
        ================================================== -->

        <header class="error-header main_header_logo">


        </header>


        <!-- =================================================
             CONTEÚDO
        ================================================== -->

        <section class="error-content">


            <!-- TEXTO -->

            <div class="error-copy">

                <span class="error-eyebrow">

                    OPS! ALGO NÃO SAIU COMO ESPERADO

                </span>


                <strong class="error-code"><?= $error->code; ?></strong>


                <h1><?= $error->title; ?></h1>


                <p class="error-description"><?= $error->message; ?></p>


                <!-- =========================================
                     AÇÕES
                ========================================== -->

                <div class="error-actions">

                    <a class="error-button error-button-primary"
                            title="<?= $error->linkTitle; ?>" href="<?= $error->link; ?>">

                        <!-- ÍCONE HOME -->

                        <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                        >

                            <path
                                    d="M3 11.5 12 4l9 7.5"
                            />

                            <path
                                    d="M5.5 10v10h13V10"
                            />

                            <path
                                    d="M9.5 20v-6h5v6"
                            />

                        </svg>

                        Voltar para o início

                    </a>


                    <a
                            href="https://wa.me/<?= htmlspecialchars(preg_replace('/\D+/', '', CONF_SITE_WHATSAPP)) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="error-button error-button-whatsapp"
                    >

                        <!-- ÍCONE WHATSAPP -->

                        <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                        >

                            <path
                                    d="M20.5 11.8a8.5 8.5 0 0 1-12.6 7.4L3 20.5l1.3-4.7A8.5 8.5 0 1 1 20.5 11.8Z"
                            />

                            <path
                                    d="M8.4 8.2c.3-.5.5-.5.8-.5h.5c.2 0 .4 0 .5.4l.8 1.9c.1.3.1.5-.1.7l-.6.8c-.2.2-.2.4 0 .7.7 1.2 1.7 2.2 2.9 2.8.3.2.5.1.7-.1l.8-1c.2-.2.4-.3.7-.2l1.9.9c.3.1.4.3.4.5 0 .4-.2 1.5-1 2.1-.6.5-1.4.8-2.2.7-1.2-.2-2.8-.7-4.7-2.4-2.2-2-3.5-4.5-3.7-5.7-.2-.8 0-1.2.5-1.6Z"
                            />

                        </svg>

                        Falar no WhatsApp

                    </a>

                </div>


                <!-- =========================================
                     AJUDA
                ========================================== -->

                <div class="error-support">

                    <div class="error-support-icon">

                        <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                        >

                            <path
                                    d="M4 13v-2a8 8 0 0 1 16 0v2"
                            />

                            <path
                                    d="M4 13H3a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2v-7Z"
                            />

                            <path
                                    d="M20 13h1a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2v-7Z"
                            />

                            <path
                                    d="M19 20c-1 2-3 2-5 2"
                            />

                        </svg>

                    </div>


                    <div>

                        <span>
                            Precisa de ajuda? Fale com a gente!
                        </span>

                        <a href="tel:+<?= htmlspecialchars(preg_replace('/\D+/', '', CONF_SITE_PHONE)) ?>">

                            <?= htmlspecialchars(CONF_SITE_PHONE) ?>

                        </a>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 ILUSTRAÇÃO
            ================================================== -->

            <div class="error-illustration">


                <!-- ELEMENTOS DECORATIVOS -->

                <svg
                        class="error-decoration"
                        viewBox="0 0 500 500"
                        aria-hidden="true"
                >

                    <path
                            d="M72 350C150 410 245 415 330 370"
                    />

                    <path
                            d="M150 65C270 8 400 85 430 200"
                    />

                    <path
                            class="dots"
                            d="M260 25C350 30 430 95 460 175"
                    />

                </svg>


                <!-- CÍRCULO -->

                <div class="error-circle">


                    <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 220 260"
                            role="img"
                            aria-labelledby="title"
                    >

                        <title id="title">
                            Página não encontrada
                        </title>


                        <!-- =====================================================
                             DEFINIÇÕES
                        ====================================================== -->

                        <defs>

                            <linearGradient
                                    id="goldGradient"
                                    x1="0"
                                    y1="0"
                                    x2="1"
                                    y2="1"
                            >

                                <stop
                                        offset="0%"
                                        stop-color="#e2bd4b"
                                />

                                <stop
                                        offset="50%"
                                        stop-color="#c5a131"
                                />

                                <stop
                                        offset="100%"
                                        stop-color="#f0ca55"
                                />

                            </linearGradient>

                        </defs>


                        <!-- =====================================================
                             PIN DE LOCALIZAÇÃO
                        ====================================================== -->

                        <path
                                d="
        M110 18

        C58 18
        22 57
        22 106

        C22 165
        110 239
        110 239

        C110 239
        198 165
        198 106

        C198 57
        162 18
        110 18
        Z
        "

                                fill="none"

                                stroke="url(#goldGradient)"

                                stroke-width="13"

                                stroke-linecap="round"

                                stroke-linejoin="round"
                        />


                        <!-- =====================================================
                             DETALHE SUPERIOR
                        ====================================================== -->

                        <path
                                d="M110 18V4"

                                fill="none"

                                stroke="url(#goldGradient)"

                                stroke-width="12"

                                stroke-linecap="round"
                        />


                        <!-- =====================================================
                             EXCLAMAÇÃO
                        ====================================================== -->

                        <path
                                d="M110 76V137"

                                fill="none"

                                stroke="#C5A131"

                                stroke-width="18"

                                stroke-linecap="round"
                        />


                        <circle
                                cx="110"
                                cy="171"
                                r="11"
                                fill="url(#goldGradient)"
                        />

                    </svg>


                </div>


                <!-- FOLHAS DECORATIVAS -->

                <div class="error-leaves error-leaves-left">

                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>

                </div>


                <div class="error-leaves error-leaves-right">

                    <span></span>
                    <span></span>
                    <span></span>

                </div>

            </div>

        </section>

    </div>

</main>
