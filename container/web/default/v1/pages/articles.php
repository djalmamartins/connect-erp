<?php $this->layout("layouts/theme"); ?>
<section class="article-hearder"></section>
<section class="blog-page">

    <!-- =====================================================
         HERO / CABEÇALHO DO BLOG
    ====================================================== -->
    <section class="blog-hero">

        <div class="blog-hero__content">

            <span class="blog-hero__eyebrow">BLOG CONNECT</span>

            <h1 class="blog-hero__title"><?= ($title ?? "Conteúdo que informa, orienta e fortalece <strong>a vida em condomínio.</strong>"); ?></h1>

            <p class="blog-hero__description"><?= ($desc ?? "Aqui você encontra dicas, novidades e informações
                essenciais para uma gestão condominial mais eficiente,
                transparente e tranquila."); ?>

            </p>

            <!-- Busca -->
            <form class="blog-search" name="search" action="<?= url("/artigos/buscar"); ?>" method="post" enctype="multipart/form-data">
                <input type="text" name="s" placeholder="Buscar artigos..." aria-label="Buscar artigos" required/>

                <button type="submit" aria-label="Pesquisar">
                    <!-- Ícone lupa -->
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="6"></circle>
                        <path d="M16 16L21 21"></path>
                    </svg>
                </button>
            </form>
            <div class="selo"></div>
        </div>


        <!-- Imagem lateral -->
        <div class="blog-hero__image">
            <span></span>
        </div>

    </section>


    <!-- =====================================================
         CATEGORIAS
    ====================================================== -->
    <?php if (empty($search)): ?>
        <section class="blog-categories">

            <div class="blog-container">

                <div class="section-title section-title--center">

                    <span></span>

                    <h2>Navegue por categorias</h2>

                    <span></span>

                </div>


                <div class="categories-grid">

                    <a href="<?= url("/artigos/em/gestao-condominial"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <!-- Prédio -->
                            <svg viewBox="0 0 24 24">
                                <path d="M4 21h16"></path>
                                <path d="M6 21V7l6-3 6 3v14"></path>
                                <path d="M9 10h2"></path>
                                <path d="M13 10h2"></path>
                                <path d="M9 14h2"></path>
                                <path d="M13 14h2"></path>
                            </svg>
                        </div>

                        <span>Gestão<br>Condominial</span>

                    </a>


                    <a href="<?= url("/artigos/em/financas"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M15 8.5c-.7-.6-1.6-.9-2.7-.9-1.5 0-2.6.8-2.6 1.9 0 1.2 1 1.7 2.8 2.1 1.7.4 2.7.9 2.7 2.2 0 1.3-1.2 2.2-2.9 2.2-1.2 0-2.3-.4-3.1-1.1"></path>
                                <path d="M12 6v12"></path>
                            </svg>
                        </div>

                        <span>Finanças</span>

                    </a>


                    <a href="<?= url("/artigos/em/legislacao"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 3v18"></path>
                                <path d="M5 6h14"></path>
                                <path d="M7 6l-4 7h8L7 6z"></path>
                                <path d="M17 6l-4 7h8l-4-7z"></path>
                                <path d="M8 21h8"></path>
                            </svg>
                        </div>

                        <span>Legislação</span>

                    </a>


                    <a href="<?= url("/artigos/em/manutencao"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.4 2.4-3-3 2.4-2.4z"></path>
                            </svg>
                        </div>

                        <span>Manutenção</span>

                    </a>


                    <a href="<?= url("/artigos/em/tecnologia"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <rect x="4" y="3" width="16" height="18" rx="2"></rect>
                                <path d="M8 7h8"></path>
                                <path d="M8 11h8"></path>
                                <path d="M8 15h5"></path>
                            </svg>
                        </div>

                        <span>Tecnologia</span>

                    </a>


                    <a href="<?= url("/artigos/em/convivencia"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <circle cx="8" cy="8" r="3"></circle>
                                <circle cx="17" cy="8" r="3"></circle>
                                <circle cx="12.5" cy="16" r="3"></circle>
                                <path d="M3 20v-2c0-2 2-4 5-4"></path>
                                <path d="M22 20v-2c0-2-2-4-5-4"></path>
                            </svg>
                        </div>

                        <span>Convivência</span>

                    </a>


                    <a href="<?= url("/artigos/em/sustentabilidade"); ?>" class="category-card">

                        <div class="category-card__icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 21V10"></path>
                                <path d="M12 14C7 14 4 11 4 6c5 0 8 3 8 8z"></path>
                                <path d="M12 11c0-4 3-7 8-7 0 5-3 8-8 8"></path>
                            </svg>
                        </div>

                        <span>Sustentabilidade</span>

                    </a>

                </div>

            </div>

        </section>
    <?php else: ?>

    <?php endif; ?>

    <!-- =====================================================
         ARTIGOS EM DESTAQUE
    ====================================================== -->
    <?php if (empty($articles) && !empty($search)): ?>
    <section class="empty-blog">
        <div class="empty-blog__content">
            <!-- SVG ilustrativo -->
            <div class="empty-blog__illustration" aria-hidden="true">
                <svg
                        width="320"
                        height="220"
                        viewBox="0 0 320 220"
                        xmlns="http://www.w3.org/2000/svg"
                        role="img"
                        aria-labelledby="title desc"
                >
                    <title id="title">Busca sem resultados</title>
                    <desc id="desc">
                        Ilustração de uma barra de busca com lupa dourada e linhas de conteúdo.
                    </desc>

                    <defs>
                        <linearGradient
                                id="topShadow"
                                x1="160"
                                y1="0"
                                x2="160"
                                y2="90"
                                gradientUnits="userSpaceOnUse"
                        >
                            <stop offset="0" stop-color="#808080" stop-opacity="0.18"/>
                            <stop offset="1" stop-color="#808080" stop-opacity="0.05"/>
                        </linearGradient>

                        <filter
                                id="softShadow"
                                x="-20%"
                                y="-20%"
                                width="140%"
                                height="140%"
                        >
                            <feDropShadow
                                    dx="0"
                                    dy="4"
                                    stdDeviation="5"
                                    flood-color="#000000"
                                    flood-opacity="0.10"
                            />
                        </filter>
                    </defs>

                    <!-- Container superior -->
                    <rect
                            x="15"
                            y="18"
                            width="290"
                            height="78"
                            rx="12"
                            fill="url(#topShadow)"
                    />

                    <rect
                            x="18"
                            y="21"
                            width="284"
                            height="72"
                            rx="10"
                            fill="#FFFFFF"
                    />

                    <!-- Campo de busca -->
                    <rect
                            x="35"
                            y="38"
                            width="180"
                            height="38"
                            rx="8"
                            fill="#F4F4F4"
                    />

                    <!-- Círculo branco atrás da lupa -->
                    <circle
                            cx="258"
                            cy="57"
                            r="31"
                            fill="#FFFFFF"
                            stroke="#F0ECE1"
                            stroke-width="2"
                            filter="url(#softShadow)"
                    />

                    <!-- Lupa -->
                    <g
                            fill="none"
                            stroke="#C5A131"
                            stroke-width="5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                    >
                        <circle
                                cx="251"
                                cy="51"
                                r="17"
                        />

                        <path
                                d="M263 64 L278 79"
                        />
                    </g>

                    <!-- Reflexo -->
                    <g
                            fill="none"
                            stroke="#C5A131"
                            stroke-width="3"
                            stroke-linecap="round"
                    >
                        <path d="M241 42 C245 38, 251 37, 256 39" />

                        <circle
                                cx="238"
                                cy="47"
                                r="1.8"
                                fill="#C5A131"
                                stroke="none"
                        />
                    </g>

                    <!-- Linhas simulando resultados -->
                    <rect
                            x="18"
                            y="116"
                            width="284"
                            height="18"
                            rx="6"
                            fill="#E6E6E6"
                    />

                    <rect
                            x="18"
                            y="145"
                            width="284"
                            height="18"
                            rx="6"
                            fill="#E6E6E6"
                    />

                    <rect
                            x="18"
                            y="174"
                            width="284"
                            height="18"
                            rx="6"
                            fill="#E6E6E6"
                    />
                </svg>
            </div>


            <!-- Texto -->
            <h2 class="empty-blog__title">
                Sua pesquisa não retornou resultados :/
            </h2>

            <p class="empty-blog__description">
                Você pesquisou por <b><?= $search; ?></b>. Tente outros termos.
            </p>

            <span class="empty-blog__line">
                <a class="radius"
                   href="<?= url("/artigos"); ?>" title="artigos">Volte aos artigos</a>
            </span>

        </div>
    </section>

    <?php elseif (empty($articles)): ?>
        <section class="empty-blog">
            <div class="empty-blog__content">

                <!-- SVG ilustrativo -->
                <div class="empty-blog__illustration" aria-hidden="true">

                    <svg
                            viewBox="0 0 420 300"
                            xmlns="http://www.w3.org/2000/svg"
                    >

                        <!-- círculo decorativo -->
                        <circle
                                cx="210"
                                cy="145"
                                r="105"
                                fill="#FAF7F0"
                        />

                        <!-- estrelas -->
                        <g
                                fill="none"
                                stroke="#C59A3D"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                        >

                            <path d="M92 102v22" />
                            <path d="M81 113h22" />

                            <path d="M334 86v20" />
                            <path d="M324 96h20" />

                            <path d="M105 208v15" />
                            <path d="M97.5 215.5h15" />

                            <path d="M320 195v14" />
                            <path d="M313 202h14" />

                        </g>


                        <!-- pequenos raios -->
                        <g
                                stroke="#C59A3D"
                                stroke-width="3"
                                stroke-linecap="round"
                        >

                            <path d="M137 62l-6-12" />
                            <path d="M153 56V42" />
                            <path d="M168 61l6-12" />

                        </g>


                        <!-- notebook -->
                        <g
                                fill="none"
                                stroke="#C59A3D"
                                stroke-width="4"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                        >

                            <rect
                                    x="135"
                                    y="83"
                                    width="165"
                                    height="132"
                                    rx="10"
                                    fill="#FFFFFF"
                            />

                            <!-- topo da janela -->
                            <path d="M135 110h165" />

                            <circle
                                    cx="152"
                                    cy="97"
                                    r="3"
                                    fill="#C59A3D"
                                    stroke="none"
                            />

                            <circle
                                    cx="164"
                                    cy="97"
                                    r="3"
                                    fill="#C59A3D"
                                    stroke="none"
                            />

                            <circle
                                    cx="176"
                                    cy="97"
                                    r="3"
                                    fill="#C59A3D"
                                    stroke="none"
                            />

                        </g>


                        <!-- conteúdo -->
                        <g
                                fill="none"
                                stroke="#C59A3D"
                                stroke-width="3"
                                stroke-linecap="round"
                        >

                            <!-- T -->
                            <path d="M165 135h32" />
                            <path d="M181 135v38" />

                            <!-- linhas -->
                            <path d="M215 138h55" />
                            <path d="M215 153h48" />
                            <path d="M215 168h38" />

                        </g>


                        <!-- imagem interna -->
                        <g
                                fill="none"
                                stroke="#C59A3D"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                        >

                            <rect
                                    x="210"
                                    y="180"
                                    width="57"
                                    height="36"
                                    rx="3"
                            />

                            <circle
                                    cx="251"
                                    cy="191"
                                    r="4"
                            />

                            <path d="M214 211l14-14 10 9 8-7 17 16" />

                        </g>


                        <!-- base notebook -->
                        <path
                                d="M118 217h198l-8 15H126z"
                                fill="#FFFFFF"
                                stroke="#C59A3D"
                                stroke-width="4"
                                stroke-linejoin="round"
                        />


                        <!-- lápis -->
                        <g
                                transform="rotate(35 302 190)"
                                fill="#FFFFFF"
                                stroke="#C59A3D"
                                stroke-width="4"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                        >

                            <rect
                                    x="294"
                                    y="145"
                                    width="17"
                                    height="83"
                                    rx="5"
                            />

                            <path d="M294 228l8.5 19 8.5-19" />

                            <path d="M294 160h17" />

                        </g>


                        <!-- pequenos detalhes -->
                        <circle
                                cx="86"
                                cy="165"
                                r="4"
                                fill="#C59A3D"
                        />

                        <circle
                                cx="350"
                                cy="150"
                                r="3"
                                fill="#C59A3D"
                        />

                    </svg>

                </div>


                <!-- Texto -->
                <h2 class="empty-blog__title">
                    Ainda estamos trabalhando aqui!
                </h2>

                <p class="empty-blog__description">
                    Nossos editores estão preparando um conteúdo de
                    primeira para você.
                </p>

                <span class="empty-blog__line"></span>

            </div>
        </section>
    <?php else: ?>
        <section class="featured-posts">
            <div class="blog-container">
                <div class="posts-header">
                    <h2><?= ($title ?? "Artigos em <strong>destaque</strong>"); ?></h2>
                    <a href="<?= url("/artigos"); ?>" class="posts-header__link">Ver todos os artigos →
                    </a>
                </div>
                <div class="posts-grid">
                    <?php foreach ($articles as $post): ?>
                        <?php $this->insert("pages/articles-list-blog", ["post" => $post]); ?>
                    <?php endforeach; ?>
                </div>
                <?= $paginator; ?>
            </div>
        </section>

    <?php endif; ?>
</section>
