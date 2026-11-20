<?php
/*
 * Termos de Uso, versão Source\Services\Privacy\LegalDocuments::TERMS (ADR 0603).
 * RASCUNHO TÉCNICO: revisar com advogado antes da produção. Sem cláusula que exclua direito do consumidor (CDC, art. 51).
 * Mudou o sentido? Troque LegalDocuments::TERMS: quem já aceitou aceita de novo na próxima entrada.
 */
?>
<p>Estes termos valem para o site, o sistema de gestão (ERP), o portal e o aplicativo do morador da <?= htmlspecialchars(CONF_SITE_NAME) ?>. Ao criar a sua conta ou continuar usando o sistema, você declara que leu e aceita estes termos e a <a href="<?= url('/politica-de-privacidade') ?>">Política de Privacidade</a>.</p>

<h2 id="quem-somos">1. Quem somos</h2>
<?= $this->insert('pages/legal/_identity') ?>

<h2 id="servico">2. O que é o serviço</h2>
<p>A <?= htmlspecialchars(CONF_SITE_NAME) ?> administra condomínios. O sistema permite à equipe da administradora, ao síndico, ao conselho e aos moradores consultar boletos e prestação de contas, fazer reservas, abrir chamados, receber avisos e documentos do condomínio. O site é institucional e recebe pedidos de proposta; nenhum serviço é vendido ou cobrado pelo site.</p>
<p>O uso do aplicativo e do portal não tem custo para o morador. Os boletos mostrados são do condomínio, com os valores, multa e juros aprovados por ele.</p>

<h2 id="conta">3. Sua conta</h2>
<ul>
    <li>A conta é pessoal e só é criada por convite do condomínio ou da administradora, para maiores de 18 anos.</li>
    <li>Guarde a sua senha e, se puder, ligue a verificação em duas etapas. Avise-nos logo se suspeitar de uso indevido.</li>
    <li>Você só vê os dados da sua unidade e do seu condomínio, conforme o seu papel (proprietário, inquilino, morador ou responsável financeiro).</li>
</ul>

<h2 id="uso">4. Uso permitido</h2>
<p>Use o sistema para os fins do condomínio. Não é permitido: tentar acessar dados de outras unidades ou condomínios, enviar conteúdo ilegal, ofensivo ou de terceiros sem autorização, enviar vírus ou atrapalhar o funcionamento do sistema. Nesses casos, o acesso pode ser suspenso, com aviso e direito de explicação, sem prejuízo de outras medidas legais.</p>

<h2 id="conteudo">5. O que você envia</h2>
<p>Mensagens, fotos e anexos enviados em chamados e ocorrências são usados para atender o seu pedido e ficam no registro do atendimento do condomínio. Envie só o necessário e não envie dados de outras pessoas sem motivo.</p>

<h2 id="disponibilidade">6. Disponibilidade</h2>
<p>Trabalhamos para manter o sistema disponível e seguro, com cópias de segurança. Podem ocorrer interrupções para manutenção ou por falhas de terceiros (internet, hospedagem, bancos). Avisamos as manutenções programadas sempre que possível.</p>

<h2 id="responsabilidade">7. Responsabilidades</h2>
<p>A <?= htmlspecialchars(CONF_SITE_NAME) ?> responde pelos serviços que presta, na forma da lei. As decisões do condomínio (valores, regras, aprovação de contas) são do condomínio, tomadas pelo síndico e pela assembleia.</p>

<h2 id="mudancas">8. Mudanças nestes termos</h2>
<p>Quando estes termos mudarem de forma importante, avisaremos e pediremos um novo aceite na sua próxima entrada no sistema.</p>

<h2 id="foro">9. Lei e foro</h2>
<p>Vale a lei brasileira. Se você for consumidor, pode buscar seus direitos no foro do seu domicílio (Código de Defesa do Consumidor, art. 101).</p>
