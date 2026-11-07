<?php $this->layout("layouts/theme"); ?>
<?php $sitePhoneDigits=preg_replace('/\D+/', '', CONF_SITE_PHONE); $siteWhatsappDigits=preg_replace('/\D+/', '', CONF_SITE_WHATSAPP); $siteAddress=trim(CONF_SITE_ADDR_STREET.', '.CONF_SITE_ADDR_NUMBER.(CONF_SITE_ADDR_COMPLEMENT?' - '.CONF_SITE_ADDR_COMPLEMENT:'').', '.CONF_SITE_ADDR_DISTRICT.', '.CONF_SITE_ADDR_CITY.' - '.CONF_SITE_ADDR_STATE.', '.CONF_SITE_ADDR_ZIPCODE, ' ,-'); ?>

<!--FEATURED-->
<article class="home_featured" id="home">

    <div class="flex content ">
        <div class="selo"></div>
        <div class="home_featured_title">
            <span class="eyebrow">GESTÃO QUE CONECTA</span>
            <h1>Conectando pessoas.<br><strong>Valorizando patrimônios.</strong></h1>
            <p>Gestão transparente, próxima e eficiente para que seu condomínio funcione melhor todos os dias.</p>
            <div class="actions">
                <a class="link" href="<?= url("/solicite-sua-proposta"); ?>">Solicitar proposta →</a>
                <a class="link icon-whatsapp" href="https://wa.me/<?= htmlspecialchars($siteWhatsappDigits) ?>" target="_blank" rel="noopener noreferrer">Falar no WhatsApp</a>
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
                <span>Famílias<br>Atendidas</span>
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
                <strong data-number-target="5">+5</strong>
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

<!-- teste-->
<section class="solutions" id="solucoes">

    <!-- Cabeçalho -->
    <div class="solutions-header">
        <div>
            <span class="eyebrow">SOLUÇÕES COMPLETAS</span>

            <h2>
                Tudo o que seu condomínio<br>
                precisa, <strong>em um só lugar.</strong>
            </h2>
        </div>

        <p>
            Da rotina financeira à manutenção, da prestação de contas
            ao planejamento. A gente oferece soluções completas
            para tornar a gestão do seu condomínio mais eficiente,
            transparente e segura.
        </p>
    </div>


    <!-- Cards -->
    <div class="solutions-grid">

        <article class="solution-card" id="solucao-financeira">
            <div class="solution-icon">
                <!-- Gestão financeira -->
                <svg viewBox="0 0 48 48">
                    <circle cx="24" cy="24" r="17"></circle>
                    <path d="M27 15h-5.5a3.5 3.5 0 0 0 0 7H26a3.5 3.5 0 0 1 0 7h-6"></path>
                    <path d="M24 12v24"></path>
                </svg>
            </div>

            <h3>Gestão<br>Financeira</h3>

            <p>
                Controle de receitas, despesas,
                fluxo de caixa e orçamentos.
            </p>


        </article>


        <article class="solution-card" id="solucao-administrativa">
            <div class="solution-icon">
                <!-- Documento -->
                <svg viewBox="0 0 48 48">
                    <path d="M14 7h14l7 7v27H14z"></path>
                    <path d="M28 7v8h7"></path>
                    <path d="M19 21h11"></path>
                    <path d="M19 26h11"></path>
                    <path d="M19 31h8"></path>
                </svg>
            </div>

            <h3>Gestão<br>Administrativa</h3>

            <p>
                Documentos, contratos,
                assembleias e obrigações em dia.
            </p>


        </article>


        <article class="solution-card" id="solucao-operacional">
            <div class="solution-icon">
                <!-- Chave inglesa -->
                <svg viewBox="0 0 48 48">
                    <path d="M28 10a9 9 0 0 0-7 14L10 35a3 3 0 0 0 4 4l11-11a9 9 0 0 0 12-11l-6 6-5-2-2-5 6-6a9 9 0 0 0-2-0.5Z"></path>
                </svg>
            </div>

            <h3>Gestão<br>Operacional</h3>

            <p>
                Manutenções, chamados,
                fornecedores e acompanhamento.
            </p>


        </article>


        <article class="solution-card">
            <div class="solution-icon">
                <!-- Gráfico -->
                <svg viewBox="0 0 48 48">
                    <path d="M8 38h34"></path>
                    <rect x="11" y="25" width="5" height="13"></rect>
                    <rect x="21" y="19" width="5" height="19"></rect>
                    <rect x="31" y="11" width="5" height="27"></rect>
                </svg>
            </div>

            <h3>Gestão de<br>Inadimplência</h3>

            <p>
                Estratégias eficazes para
                reduzir inadimplência e recuperar valores.
            </p>


        </article>


        <article class="solution-card">
            <div class="solution-icon">
                <!-- Síndico -->
                <svg viewBox="0 0 48 48">
                    <circle cx="24" cy="16" r="7"></circle>
                    <path d="M11 38c1-8 6-12 13-12s12 4 13 12"></path>
                </svg>
            </div>

            <h3>Síndico<br>Profissional</h3>

            <p>
                Acompanhamento estratégico
                e apoio nas decisões.
            </p>


        </article>


        <article class="solution-card">
            <div class="solution-icon">
                <!-- Comunicação -->
                <svg viewBox="0 0 48 48">
                    <path d="M9 12h23a5 5 0 0 1 5 5v11a5 5 0 0 1-5 5H20l-7 6v-6H9a5 5 0 0 1-5-5V17a5 5 0 0 1 5-5Z"></path>
                    <path d="M18 20h11"></path>
                    <path d="M18 25h7"></path>
                    <path d="M36 19h3a4 4 0 0 1 4 4v7"></path>
                </svg>
            </div>

            <h3>Comunicação<br>e Transparência</h3>

            <p>
                Informações claras e
                comunicação eficiente com moradores.
            </p>


        </article>


        <article class="solution-card">
            <div class="solution-icon">
                <!-- Segurança -->
                <svg viewBox="0 0 48 48">
                    <path d="M24 6l15 6v10c0 10-6 17-15 21C15 39 9 32 9 22V12z"></path>
                    <path d="m17 24 5 5 10-11"></path>
                </svg>
            </div>

            <h3>Seguros e<br>Conformidades</h3>

            <p>
                Proteção para o condomínio
                e adequação às normas.
            </p>


        </article>

    </div>
</section>


<!-- =====================================================
     APP
===================================================== -->

<section class="app-section" id="prestacao-de-contas">

    <div class="app-content">

        <!-- Texto -->
        <div class="app-copy">

      <span class="eyebrow app-eyebrow">
        TECNOLOGIA QUE APROXIMA
      </span>

            <h2>
                Gestão financeira na
                <strong>palma da sua mão.</strong>
            </h2>

            <p class="app-description">
                Nosso aplicativo exclusivo oferece praticidade,
                transparência e agilidade para síndicos e moradores.
            </p>


            <div class="app-features">

                <ul>
                    <li>Financeiro em tempo real</li>
                    <li>Boletos e 2ª via</li>
                    <li>Prestação de contas</li>
                    <li>Comunicados e avisos</li>
                </ul>

                <ul>
                    <li>Ocorrências e manutenções</li>
                    <li>Reservas de áreas comuns</li>
                    <li>Documentos do condomínio</li>
                    <li>E muito mais!</li>
                </ul>

            </div>


            <!-- Lojas -->
            <div class="app-stores">

                <a href="#"><img class="store" src="<?= theme("/assets/images/google_play.png"); ?>" alt="Aplicativo">
                </a>

                <a href="#"><img class="store" src="<?= theme("/assets/images/apple_store.png"); ?>" alt="Aplicativo">
                </a>

            </div>

        </div>


        <!-- Celulares -->
        <div class="phones">
            <img class="app-image" src="<?= theme("/assets/images/app-cel.png"); ?>" alt="Aplicativo">
        </div>


        <!-- Benefícios -->
        <div class="app-benefits">

            <div class="benefit">
                <div class="benefit-icon">
                    <div class="solution-icon">
                        <svg viewBox="0 0 64 64">
                            <path d="M32 6l21 8v16c0 13-8 22-21 28C19 52 11 43 11 30V14z"/>
                            <path d="M23 31l6 6 12-14"/>
                        </svg>
                    </div>
                </div>

                <div>
                    <h3>Seguro</h3>
                    <p>
                        Seus dados protegidos<br>
                        com segurança total.
                    </p>
                </div>
            </div>


            <div class="benefit">
                <div class="benefit-icon">
                    <svg viewBox="0 0 64 64">
                        <rect x="20" y="8" width="24" height="43" rx="4"/>
                        <path d="M27 14h10"/>
                        <circle cx="32" cy="45" r="2"/>

                        <circle cx="47" cy="30" r="7"/>
                        <path d="M43 30l3 3 6-7"/>
                    </svg>
                </div>

                <div>
                    <h3>Simples</h3>
                    <p>
                        Interface fácil e intuitiva<br>
                        para todos.
                    </p>
                </div>
            </div>


            <div class="benefit">
                <div class="benefit-icon">
                    <svg viewBox="0 0 48 48">
                        <circle
                                cx="24"
                                cy="24"
                                r="17"
                        />
                        <path
                                d="M16.5 24L21.5 29L32 18"
                        />
                    </svg>
                </div>

                <div>
                    <h3>Completo</h3>
                    <p>
                        Tudo o que você precisa<br>
                        em um só lugar.
                    </p>
                </div>
            </div>

        </div>

    </div>

</section>
<!-- teste-->

<section class="method" id="apcontrole">

    <div class="method-container">

        <!-- LADO ESQUERDO -->
        <div class="method-content">

            <span class="method-label">
                NOSSO MÉTODO
            </span>

            <h2>Método Connect</h2>


            <div class="method-timeline">

                <!-- ETAPA 01 -->
                <div class="method-step">

                    <div class="method-number">
                        01
                    </div>

                    <h3>
                        Diagnóstico
                    </h3>

                    <p>
                        Analisamos a realidade do seu
                        condomínio.
                    </p>

                </div>


                <!-- ETAPA 02 -->
                <div class="method-step">

                    <div class="method-number">
                        02
                    </div>

                    <h3>
                        Planejamento
                    </h3>

                    <p>
                        Criamos estratégias
                        personalizadas
                        para cada necessidade.
                    </p>

                </div>


                <!-- ETAPA 03 -->
                <div class="method-step">

                    <div class="method-number">
                        03
                    </div>

                    <h3>
                        Implantação
                    </h3>

                    <p>
                        Colocamos o plano
                        em prática com
                        organização e foco.
                    </p>

                </div>


                <!-- ETAPA 04 -->
                <div class="method-step">

                    <div class="method-number">
                        04
                    </div>

                    <h3>
                        Execução
                    </h3>

                    <p>
                        Acompanhamos
                        de perto todas
                        as atividades.
                    </p>

                </div>


                <!-- ETAPA 05 -->
                <div class="method-step">

                    <div class="method-number">
                        05
                    </div>

                    <h3>
                        Monitoramento
                    </h3>

                    <p>
                        Controlamos
                        indicadores e
                        resultados.
                    </p>

                </div>


                <!-- ETAPA 06 -->
                <div class="method-step">

                    <div class="method-number">
                        06
                    </div>

                    <h3>
                        Melhoria<br>
                        Contínua
                    </h3>

                    <p>
                        Buscamos sempre
                        mais eficiência
                        e valorização.
                    </p>

                </div>

            </div>

        </div>


        <!-- LADO DIREITO -->
        <aside class="method-result">

            <span class="method-result-label">
                MAIS VALOR PARA O SEU CONDOMÍNIO
            </span>

            <h2>
                Mais valor para o seu condomínio.
            </h2>


            <ul>

                <li>
                    <span class="check">✓</span>
                    <span>Redução da inadimplência</span>
                </li>

                <li>
                    <span class="check">✓</span>
                    <span>Economia em contratos e despesas</span>
                </li>

                <li>
                    <span class="check">✓</span>
                    <span>Prestação de contas em dia</span>
                </li>

                <li>
                    <span class="check">✓</span>
                    <span>Condomínio mais organizado</span>
                </li>

                <li>
                    <span class="check">✓</span>
                    <span>Decisões mais seguras</span>
                </li>

                <li>
                    <span class="check">✓</span>
                    <span>Moradores mais satisfeitos</span>
                </li>

            </ul>

        </aside>

    </div>

</section>


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

<section class="section" id="blog">
    <div class="container">
        <header class="section__head reveal visible"><span class="eyebrow">DO NOSSO BLOG</span>
            <h2>Informação para uma <em>gestão melhor.</em></h2></header>
        <div class="cards cards--blog">
            <?php foreach (($articles ?? []) as $post): ?>
                <?php $this->insert("pages/articles-list", ["post" => $post]); ?>
            <?php endforeach; ?>
            <?php if (empty($articles)): ?>
                <article class="blog-empty">
                    <h3>Novos conteúdos em preparação</h3>
                    <p>Em breve, você encontrará aqui orientações para uma gestão condominial mais simples e segura.</p>
                </article>
            <?php endif; ?>
        </div>
        <div class="blog-all-posts">
            <a href="<?= url("/artigos"); ?>" title="Ver todos os artigos">
                Ver todos os artigos →
            </a>
        </div>
    </div>
</section>

<!-- =========================
     SEÇÃO LOCALIZAÇÃO
========================= -->
<section class="localizacao" id="contato">

    <div class="localizacao-container">

        <!-- COLUNA ESQUERDA -->
        <div class="localizacao-info">

            <span class="section-label">ONDE ESTAMOS</span>

            <h2>
                Fale com a Connect<br>
                <span>Condomínios.</span>
            </h2>

            <p class="localizacao-descricao">
                Estamos prontos para ouvir você e oferecer
                as melhores soluções para o seu condomínio.
            </p>

            <div class="contatos-grid">

                <!-- Telefone -->
                <a href="tel:+<?= htmlspecialchars($sitePhoneDigits) ?>" class="contato-item">

                    <div class="contato-icon">
                        <svg viewBox="0 0 24 24">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2
                            19.79 19.79 0 0 1-8.63-3.07
                            19.5 19.5 0 0 1-6-6
                            A19.79 19.79 0 0 1 2.12 4.18
                            2 2 0 0 1 4.11 2h3
                            a2 2 0 0 1 2 1.72
                            12.84 12.84 0 0 0 .7 2.81
                            2 2 0 0 1-.45 2.11L8.09 9.91
                            a16 16 0 0 0 6 6l1.27-1.27
                            a2 2 0 0 1 2.11-.45
                            12.84 12.84 0 0 0 2.81.7
                            A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </div>

                    <div>
                        <strong>Telefone</strong>
                        <span><?= htmlspecialchars(CONF_SITE_PHONE) ?></span>
                    </div>

                </a>


                <!-- WhatsApp -->
                <a
                        href="https://wa.me/<?= htmlspecialchars($siteWhatsappDigits) ?>"
                        target="_blank"
                        class="contato-item"
                >

                    <div class="contato-icon">
                        <svg viewBox="0 0 24 24">
                            <path d="M20.52 3.48A11.85 11.85 0 0 0 12.05
                            0C5.52 0 .2 5.32.2 11.85
                            c0 2.09.55 4.13 1.59 5.93L.1 24
                            l6.37-1.67a11.85 11.85 0 0 0
                            5.58 1.41h.01c6.53 0 11.85-5.32
                            11.85-11.85 0-3.17-1.23-6.14-3.39-8.41z
                            M12.06 21.74h-.01a9.85 9.85 0 0 1-5.02-1.37
                            l-.36-.21-3.78.99 1.01-3.68-.23-.38
                            a9.86 9.86 0 0 1-1.51-5.24
                            C2.16 6.43 6.59 2 12.05 2
                            c2.64 0 5.12 1.03 6.99 2.9
                            a9.81 9.81 0 0 1 2.89 6.99
                            c0 5.46-4.43 9.89-9.87 9.89z"/>
                            <path d="M17.6 14.24c-.3-.15-1.77-.87-2.05-.97
                            -.27-.1-.47-.15-.67.15-.2.3-.77.97-.95
                            1.17-.17.2-.35.22-.65.07
                            -.3-.15-1.26-.46-2.4-1.48
                            -.89-.79-1.49-1.76-1.67-2.06
                            -.17-.3-.02-.46.13-.61
                            .13-.13.3-.35.45-.52
                            .15-.17.2-.3.3-.5
                            .1-.2.05-.37-.02-.52
                            -.07-.15-.67-1.62-.92-2.22
                            -.24-.58-.49-.5-.67-.51h-.57
                            c-.2 0-.52.07-.8.37
                            -.27.3-1.05 1.02-1.05 2.49
                            s1.07 2.89 1.22 3.09
                            c.15.2 2.1 3.21 5.09 4.5
                            .71.31 1.27.49 1.7.63
                            .71.23 1.35.2 1.86.12
                            .57-.08 1.77-.72 2.02-1.42
                            .25-.7.25-1.3.17-1.42
                            -.07-.12-.27-.2-.57-.35z"/>
                        </svg>
                    </div>

                    <div>
                        <strong>WhatsApp</strong>
                        <span><?= htmlspecialchars(CONF_SITE_PHONE) ?></span>
                    </div>

                </a>


                <!-- E-mail -->
                <a
                        href="mailto:<?= htmlspecialchars(CONF_MAIL_SUPPORT) ?>"
                        class="contato-item"
                >

                    <div class="contato-icon">
                        <svg viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="m3 7 9 6 9-6"/>
                        </svg>
                    </div>

                    <div>
                        <strong>E-mail</strong>
                        <span><?= htmlspecialchars(CONF_MAIL_SUPPORT) ?></span>
                    </div>

                </a>


                <!-- Horário -->
                <div class="contato-item">

                    <div class="contato-icon">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3 2"/>
                        </svg>
                    </div>

                    <div>
                        <strong>Horário de atendimento</strong>
                        <span>Segunda a sexta: 08h às 18h</span>
                    </div>

                </div>

                <!-- Endereço -->
                <div class="contato-item endereco">

                    <div class="contato-icon">
                        <svg viewBox="0 0 24 24">
                            <path d="M20 10c0 5-8 11-8 11S4 15 4 10
            a8 8 0 1 1 16 0z"/>
                            <circle cx="12" cy="10" r="2.5"/>
                        </svg>
                    </div>

                    <div>
                        <strong>Endereço</strong>

                        <span>
            <?= htmlspecialchars(CONF_SITE_ADDR_STREET.', '.CONF_SITE_ADDR_NUMBER) ?><?= CONF_SITE_ADDR_COMPLEMENT ? ' – '.htmlspecialchars(CONF_SITE_ADDR_COMPLEMENT) : '' ?><br>
            <?= htmlspecialchars(CONF_SITE_ADDR_DISTRICT.', '.CONF_SITE_ADDR_CITY.' – '.CONF_SITE_ADDR_STATE) ?><br>
            CEP: <?= htmlspecialchars(CONF_SITE_ADDR_ZIPCODE) ?>
        </span>
                    </div>

                </div>

            </div>

        </div>


        <!-- COLUNA DIREITA / MAPA -->
        <div class="mapa-wrapper">

            <div class="mapa">

                <iframe
                        src="https://www.google.com/maps?q=<?= rawurlencode($siteAddress) ?>&output=embed"
                        loading="lazy"
                        allowfullscreen
                        referrerpolicy="no-referrer-when-downgrade">
                </iframe>


                <!-- MARCADOR CONNECT -->
                <div class="connect-marker">

                    <div class="marker-pin">

                        <img
                                src="<?= theme("/assets/images/marca_connect.svg"); ?>"
                                alt="Connect Condomínios"
                        >

                    </div>

                    <div class="marker-shadow"></div>

                </div>

            </div>

        </div>

    </div>

</section>
