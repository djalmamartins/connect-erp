<?php $this->layout("layouts/sales"); ?>
<!--FEATURED-->
<article class="home_featured sales-hero" id="home">

    <div class="flex content ">
        <div class="home_featured_title">
            <span class="eyebrow">GESTÃO CONDOMINIAL QUE CONECTA</span>
            <h1>Sua tranquilidade começa com uma<br><strong>boa gestão.</strong></h1>
            <p>Administração próxima, transparente e eficiente
                para cuidar do seu condomínio e dar mais segurança
                às suas decisões.</p>
            <!-- Benefícios -->
            <ul class="proposal-benefits">

                <li>
                    <span class="proposal-check">✓</span>
                    Gestão financeira e administrativa completa
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Prestação de contas clara, organizada e transparente
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Tecnologia para facilitar a rotina do condomínio
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Atendimento próximo, ágil e especializado
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Estratégias para reduzir a inadimplência e recuperar valores
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Apoio estratégico ao síndico e ao conselho
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Comunicação clara com moradores e condôminos
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Seguros, conformidades e obrigações em dia
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Gestão de fornecedores e contratos
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Planejamento de manutenções preventivas e corretivas
                </li>

                <li>
                    <span class="proposal-check">✓</span>
                    Organização de assembleias, documentos e processos
                </li>

            </ul>
        </div>
        <div class="home_featured_title">
            <!-- ==========================================
           FORMULÁRIO
      =========================================== -->
            <div class="proposal-form-card">

                <header class="proposal-form-header">

                    <h2>
                        Solicite uma proposta
                    </h2>

                    <p>
                        Conte um pouco sobre o seu condomínio.
                        Nossa equipe entrará em contato para entender
                        sua necessidade e preparar uma proposta personalizada.
                    </p>

                </header>


                <form class="proposal-form" action="<?= url('/solicite-sua-proposta') ?>" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="source_url" value="<?= htmlspecialchars(url('/solicite-sua-proposta')) ?>">
                    <label class="proposal-honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>

                    <!-- Nome -->
                    <div class="proposal-form-group">

                        <label for="proposal-name">
                            Nome
                            <span>*</span>
                        </label>

                        <input
                                type="text"
                                id="proposal-name"
                                name="name"
                                placeholder="Seu nome completo"
                                required
                        >

                    </div>

                    <div class="proposal-form-group proposal-form-group-full">
                        <label for="proposal-message">Como podemos ajudar?</label>
                        <textarea id="proposal-message" name="message" rows="3" maxlength="1200" placeholder="Conte brevemente o que seu condomínio precisa"></textarea>
                    </div>


                    <!-- WhatsApp -->
                    <div class="proposal-form-group">

                        <label for="proposal-whatsapp">
                            WhatsApp
                            <span>*</span>
                        </label>

                        <input
                                type="tel"
                                id="proposal-whatsapp"
                                name="whatsapp"
                                placeholder="(31) 99999-9999"
                                required
                        >

                    </div>


                    <!-- E-mail -->
                    <div class="proposal-form-group">

                        <label for="proposal-email">
                            E-mail
                            <span>*</span>
                        </label>

                        <input
                                type="email"
                                id="proposal-email"
                                name="email"
                                placeholder="seu@email.com"
                                required
                        >

                    </div>


                    <!-- Condomínio -->
                    <div class="proposal-form-group">

                        <label for="proposal-condominio">
                            Nome do condomínio
                            <span>*</span>
                        </label>

                        <input
                                type="text"
                                id="proposal-condominio"
                                name="condominio"
                                placeholder="Ex.: Condomínio Jardim das Flores"
                                required
                        >

                    </div>


                    <!-- Unidades -->
                    <div class="proposal-form-group">

                        <label for="proposal-units">
                            Número de unidades
                            <span>*</span>
                        </label>

                        <input
                                type="number"
                                id="proposal-units"
                                name="units"
                                min="1"
                                placeholder="Ex.: 80"
                                required
                        >

                    </div>


                    <!-- Perfil -->
                    <div class="proposal-form-group">

                        <label for="proposal-profile">
                            Você é:
                            <span>*</span>
                        </label>

                        <select
                                id="proposal-profile"
                                name="profile"
                                required
                        >

                            <option value="">
                                Selecione sua função
                            </option>

                            <option value="sindico">
                                Síndico
                            </option>

                            <option value="conselheiro">
                                Conselheiro
                            </option>

                            <option value="morador">
                                Morador
                            </option>

                            <option value="administrador">
                                Administrador
                            </option>

                            <option value="outro">
                                Outro
                            </option>

                        </select>

                    </div>


                    <!-- Botão -->
                    <button
                            type="submit"
                            class="proposal-submit"
                    >

                        <svg viewBox="0 0 24 24">
                            <path d="M22 2L11 13"/>
                            <path d="M22 2l-7 20-4-9-9-4z"/>
                        </svg>

                        QUERO RECEBER UMA PROPOSTA

                    </button>


                    <!-- Privacidade -->
                    <div class="proposal-security">

                        <svg viewBox="0 0 24 24">
                            <rect
                                    x="5"
                                    y="10"
                                    width="14"
                                    height="11"
                                    rx="2"
                            />

                            <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        </svg>

                        <p>
                            Seus dados serão utilizados apenas para
                            entrarmos em contato sobre sua solicitação.
                        </p>

                    </div>

                </form>

            </div>
        </div>

    </div>
</article>

<!--FEATURES-->

<section class="numbers-bar">
    <div class="numbers-container">

        <div class="number-item">
            <div class="number-icon">
                <svg viewBox="0 0 89 90" aria-hidden="true">
                    <g>
                        <path class="st0"
                              d="M60.1,98.2h-38V12.2c0-3.6,2.9-6.6,6.6-6.6h24.8c3.6,0,6.6,2.9,6.6,6.6V98.2z"/>
                        <rect x="31.6" y="16.3" width="8" height="7.7"/>
                        <rect x="31.6" y="31.4" width="8" height="7.7"/>
                        <rect x="31.6" y="46.6" width="8" height="7.7"/>
                        <rect x="31.6" y="61.8" width="8" height="7.7"/>
                        <rect x="31.6" y="77" width="8" height="7.7"/>
                        <rect x="43.6" y="16.3" width="8" height="7.7"/>
                        <rect x="43.6" y="31.4" width="8" height="7.7"/>
                        <rect x="43.6" y="46.6" width="8" height="7.7"/>
                        <rect x="43.6" y="61.8" width="8" height="7.7"/>
                        <rect x="43.6" y="77" width="8" height="7.7"/>
                        <path class="st0" d="M3.6,100.1V38c0-3.5,2.9-6.3,6.6-6.3"/>
                        <rect x="1.6" y="96.3" width="16" height="3.9"/>
                        <rect x="9.6" y="29.8" width="8" height="3.9"/>
                        <rect x="9.2" y="39.9" width="7.4" height="7.1"/>
                        <rect x="9.2" y="53.9" width="7.4" height="7.1"/>
                        <rect x="9.2" y="67.9" width="7.4" height="7.1"/>
                        <rect x="9.2" y="81.4" width="7.4" height="7.1"/>
                        <polygon points="43.6,7.6 43.6,4.7 39.6,-0.1 39.6,7.6 	"/>
                        <path class="st0" d="M77.6,100.1V38c0-3.5-2.9-6.3-6.6-6.3"/>
                        <rect x="63.6" y="96.3" transform="matrix(-1 -4.320112e-11 4.320112e-11 -1 143.2773 196.3794)"
                              width="16" height="3.9"/>
                        <rect x="63.6" y="29.8" transform="matrix(-1 -4.308743e-11 4.308743e-11 -1 135.2773 63.3654)"
                              width="8" height="3.9"/>
                        <rect x="64.6" y="39.9" transform="matrix(-1 -4.330681e-11 4.330681e-11 -1 136.6697 86.8765)"
                              width="7.4" height="7.1"/>
                        <rect x="64.6" y="53.9" transform="matrix(-1 -4.306079e-11 4.306079e-11 -1 136.6697 114.9324)"
                              width="7.4" height="7.1"/>
                        <rect x="64.6" y="67.9" transform="matrix(-1 -4.355328e-11 4.355328e-11 -1 136.6697 142.9884)"
                              width="7.4" height="7.1"/>
                        <rect x="64.6" y="81.4" transform="matrix(-1 -4.330681e-11 4.330681e-11 -1 136.6697 169.9767)"
                              width="7.4" height="7.1"/>
                    </g>
                </svg>
            </div>

            <div class="number-content">
                <strong data-number-target="40">+40</strong>
                <span>Condomínios<br>administrados</span>
            </div>
        </div>

        <div class="number-item">
            <div class="number-icon">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <circle cx="32" cy="18" r="7"/>
                    <circle cx="15" cy="24" r="6"/>
                    <circle cx="49" cy="24" r="6"/>
                    <path d="M20 51c0-10 5-16 12-16s12 6 12 16"/>
                    <path d="M5 49c0-8 4-13 10-13 4 0 7 2 9 6"/>
                    <path d="M59 49c0-8-4-13-10-13-4 0-7 2-9 6"/>
                </svg>
            </div>

            <div class="number-content">
                <strong data-number-target="1300">+1300</strong>
                <span>Unidades<br>administradas</span>
            </div>
        </div>


        <div class="number-item">
            <div class="number-icon">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <circle cx="32" cy="23" r="14"/>
                    <path d="M25 36l-5 20 12-7 12 7-5-20"/>
                    <path d="M32 15l2.5 5 5.5.8-4 4 1 5.7-5-2.7-5 2.7 1-5.7-4-4 5.5-.8z"/>
                </svg>
            </div>

            <div class="number-content">
                <strong data-number-target="10">+10</strong>
                <span>Anos de<br>experiência</span>
            </div>
        </div>


        <div class="number-item">
            <div class="number-icon">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <path d="M32 5l7.2 16.5 17.8 1.8-13.3 11.8 3.8 17.4L32 43.4 16.5 52.5l3.8-17.4L7 23.3l17.8-1.8z"/>
                </svg>
            </div>

            <div class="number-content">
                <strong data-number-target="96">96%</strong>
                <span>Índice de satisfação<br>dos clientes</span>
            </div>
        </div>

    </div>
</section>



<!-- fim -->
<section class="about-team" id="about">

    <div class="about-team-container">

        <!-- =================================
             LADO ESQUERDO
        ================================== -->

<div class="about-team-content">

            <span class="about-team-label">
                ESPECIALISTAS EM GESTÃO CONDOMINIAL
            </span>

    <h2>
        Uma equipe comprometida com excelência.
    </h2>

    <p class="about-team-description">
        A Connect Condomínios reúne uma equipe multidisciplinar preparada
        para oferecer uma administração moderna, transparente e eficiente.
        Cuidamos do seu condomínio como se fosse nosso,
        com proximidade, compromisso e excelência.
    </p>


    <!-- DIFERENCIAIS -->

    <div class="about-team-features">

        <div class="about-team-feature">

            <div class="about-team-feature-icon">
                <svg viewBox="0 0 64 64">
                    <circle cx="32" cy="18" r="7"/>
                    <circle cx="16" cy="24" r="5"/>
                    <circle cx="48" cy="24" r="5"/>

                    <path d="M19 51c0-10 5-16 13-16s13 6 13 16"/>
                    <path d="M6 48c0-7 4-12 10-12 4 0 7 2 9 5"/>
                    <path d="M58 48c0-7-4-12-10-12-4 0-7 2-9 5"/>
                </svg>
            </div>

            <span>
                        Equipe<br>
                        especializada
                    </span>

        </div>


        <div class="about-team-feature">

            <div class="about-team-feature-icon">
                <svg viewBox="0 0 89 94">
                    <path d="M16.8,79.2l3.3-12.1c-2-3.5-3.1-7.6-3.1-11.7C17,42.5,27.5,32,40.3,32c6.3,0,12.1,2.4,16.5,6.9c4.4,4.4,6.8,10.3,6.8,16.5
	c0,12.9-10.5,23.4-23.4,23.4c-3.9,0-7.8-1-11.2-2.8C29.2,75.9,16.8,79.2,16.8,79.2z M29.7,71.7c3.3,2,6.4,3.1,10.6,3.1
	c10.7,0,19.4-8.7,19.4-19.4c0-10.7-8.7-19.5-19.4-19.5c-10.7,0-19.4,8.7-19.4,19.4c0,4.4,1.3,7.7,3.4,11.1l-2,7.2
	C22.4,73.6,29.7,71.7,29.7,71.7z M52.1,60.9c-0.1-0.2-0.5-0.4-1.1-0.7c-0.6-0.3-3.5-1.7-4-1.9c-0.5-0.2-0.9-0.3-1.3,0.3
	c-0.4,0.6-1.5,1.9-1.9,2.3s-0.7,0.4-1.3,0.1s-2.5-0.9-4.7-2.9c-1.7-1.5-2.9-3.5-3.3-4c-0.3-0.6,0-0.9,0.3-1.2c0.3-0.3,0.6-0.7,0.9-1
	c0.3-0.3,0.4-0.6,0.6-1c0.2-0.4,0.1-0.7,0-1c-0.1-0.3-1.3-3.2-1.8-4.3c-0.5-1.1-1-1-1.3-1l-1.1,0c-0.4,0-1,0.1-1.6,0.7
	c-0.5,0.6-2,2-2,4.9c0,2.9,2.1,5.7,2.4,6c0.3,0.4,4.1,6.3,10,8.8c1.4,0.6,2.5,1,3.3,1.2c1.4,0.4,2.7,0.4,3.7,0.2
	c1.1-0.2,3.5-1.4,3.9-2.8C52.3,62.4,52.3,61.2,52.1,60.9z"></path>
                    <path class="st0" d="M5.1,42.4C5.1,22.8,21,7,40.5,7s35.4,15.8,35.4,35.4"></path>
                    <path d="M11.4,64.2H0.4V41.6h10.9c-1,1.5-3.4,5.6-3.4,11.3C8,54.7,8.2,59.4,11.4,64.2z"></path>
                    <path d="M69.1,64.2H80V41.6H69.1c1,1.5,3.4,5.6,3.4,11.3C72.5,54.7,72.2,59.4,69.1,64.2z"></path>
                    <path class="st0" d="M39.9,89.9c19.6,0,35.4-15.8,35.4-35.4"></path>
                    <path class="st0" d="M5.1,65.5"></path>
                    <path d="M43.5,92.9h-9.9c-1,0-1.8-0.8-1.8-1.8v-2.5c0-1,0.8-1.8,1.8-1.8h9.9V92.9z"></path>
                </svg>
            </div>

            <span>
                        Atendimento<br>
                        próximo
                    </span>

        </div>


        <div class="about-team-feature">

            <div class="about-team-feature-icon">
                <svg viewBox="0 0 64 64">
                    <path d="M32 6l21 8v16c0 13-8 22-21 28C19 52 11 43 11 30V14z"/>
                    <path d="M23 31l6 6 12-14"/>
                </svg>
            </div>

            <span>
                        Processos<br>
                        organizados
                    </span>

        </div>


        <div class="about-team-feature">

            <div class="about-team-feature-icon">
                <svg viewBox="0 0 64 64">
                    <path d="M8 53h48"/>
                    <path d="M14 48V34"/>
                    <path d="M26 48V27"/>
                    <path d="M38 48V19"/>
                    <path d="M50 48V11"/>

                    <path d="M12 27l12-8 12 2 15-12"/>
                    <path d="M44 9h7v7"/>
                </svg>
            </div>

            <span>
                        Foco em<br>
                        resultados
                    </span>

        </div>

    </div>

</div>


<!-- =================================
     LADO DIREITO — IMAGENS
================================== -->

<div class="about-team-images">

    <!-- IMAGEM GRANDE -->

    <div class="about-team-image about-team-image-main">

        <img src="<?= theme("/assets/images/gestao-a.png"); ?>" alt="Equipe Connect Condomínios">

    </div>


    <!-- IMAGEM INFERIOR ESQUERDA -->

    <div class="about-team-image about-team-image-bottom-left">

        <img
                src="<?= theme("/assets/images/gestao-b.png"); ?>"
                alt="Gestão e planejamento condominial"
        >

    </div>


    <!-- IMAGEM INFERIOR DIREITA -->

    <div class="about-team-image about-team-image-bottom-right">

        <img
                src="<?= theme("/assets/images/gestao-c.png"); ?>"
                alt="Reunião da equipe Connect Condomínios"
        >

    </div>

</div>

</div>

</section>

<?= $this->insert("pages/testimonials", ["briefs" => ($briefs ?? [])]); ?>
