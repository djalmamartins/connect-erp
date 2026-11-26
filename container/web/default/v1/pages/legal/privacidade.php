<?php
/*
 * Política de Privacidade, versão Source\Services\Privacy\LegalDocuments::PRIVACY (ADR 0604).
 * RASCUNHO TÉCNICO: revisar com advogado antes da produção. Base: docs/security/LGPD_REGISTRO_OPERACOES.md.
 * Mudou o sentido? Troque LegalDocuments::PRIVACY.
 */
?>
<p>Esta política explica, em linguagem simples, quais dados pessoais a <?= htmlspecialchars(CONF_SITE_NAME) ?> trata, para quê, por quanto tempo, com quem compartilha e como você exerce seus direitos, conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018, LGPD).</p>

<h2 id="quem-somos">1. Quem somos</h2>
<?= $this->insert('pages/legal/_identity') ?>
<p>A <?= htmlspecialchars(CONF_SITE_NAME) ?> é uma administradora de condomínios. Ela decide sobre os dados do site, dos pedidos de proposta e da própria equipe (é a <strong>controladora</strong>). Nos dados dos condomínios que administra (moradores, unidades, cobrança, portaria, assembleias), quem decide é o condomínio, representado pelo síndico, e a <?= htmlspecialchars(CONF_SITE_NAME) ?> trata esses dados em nome dele (é a <strong>operadora</strong>), conforme o contrato de administração.</p>

<h2 id="dados">2. Quais dados tratamos e para quê</h2>
<ul>
    <li><strong>Pedido de proposta no site:</strong> nome, e-mail, WhatsApp, condomínio, número de unidades, sua função e a mensagem. Para responder ao pedido e preparar a proposta.</li>
    <li><strong>Novidades e conteúdos:</strong> nome e e-mail ou WhatsApp, só se você marcar a opção. Você pode cancelar quando quiser.</li>
    <li><strong>Cadastro no condomínio:</strong> nome, CPF ou CNPJ, e-mail, telefone, unidade e endereço de cobrança de proprietários, inquilinos, moradores e responsáveis financeiros. Para administrar o condomínio, emitir e cobrar a taxa condominial e enviar avisos.</li>
    <li><strong>Cobrança:</strong> valores, boletos, Pix e histórico de pagamento. Para emitir, receber, cobrar atrasos e prestar contas ao condomínio.</li>
    <li><strong>Portaria e visitas:</strong> nome, documento, telefone, placa e horário de entrada e saída de visitantes e prestadores. Para a segurança do condomínio.</li>
    <li><strong>Assembleias e conselho:</strong> nome, presença e votos. Para convocar, registrar e lavrar a ata.</li>
    <li><strong>Aplicativo e portal do morador:</strong> e-mail, senha (guardada só como código irreversível), foto de perfil opcional, aparelho e verificação em duas etapas. Para dar acesso aos boletos, reservas, chamados e documentos.</li>
    <li><strong>Chamados e atendimento:</strong> nome, contato, mensagens e anexos que você envia. Para atender pedidos e ocorrências.</li>
    <li><strong>Segurança:</strong> endereço IP, navegador, data e ação feita no sistema. Para evitar fraude e provar quem fez o quê.</li>
</ul>
<p>Pedimos só o que é necessário para cada finalidade. Campos opcionais são marcados como opcionais.</p>

<h2 id="bases">3. Bases legais (LGPD, art. 7º)</h2>
<ul>
    <li><strong>Contrato e procedimentos antes dele</strong> (inciso V): proposta, administração do condomínio, aplicativo, cobrança e atendimento.</li>
    <li><strong>Obrigação legal</strong> (inciso II): guarda contábil e fiscal, registros de acesso (Marco Civil da Internet, art. 15), convenção e Código Civil.</li>
    <li><strong>Exercício regular de direitos</strong> (inciso VI) e <strong>proteção do crédito</strong> (inciso X): cobrança de taxas em atraso.</li>
    <li><strong>Legítimo interesse</strong> (inciso IX): segurança da portaria e do sistema, sempre dentro do que você pode esperar.</li>
    <li><strong>Consentimento</strong> (inciso I): novidades e conteúdos, e cookies opcionais. Você pode retirar a qualquer momento, sem prejuízo do que já foi feito.</li>
</ul>

<h2 id="prazos">4. Por quanto tempo guardamos</h2>
<ul>
    <li>Pedido de proposta que não virou contrato: até 2 anos depois do último contato.</li>
    <li>Cadastro no condomínio: enquanto houver vínculo com a unidade. Depois, o necessário para a contabilidade e a cobrança (em regra, 5 anos), e o restante é anonimizado.</li>
    <li>Cobrança e prestação de contas: 5 anos após o pagamento, ou mais se houver ação judicial.</li>
    <li>Atas e presença em assembleia: permanente, porque são documentos do condomínio.</li>
    <li>Registros de acesso ao sistema: no mínimo 6 meses (Marco Civil da Internet).</li>
    <li>Conta do aplicativo: enquanto existir. Ao excluir a conta, a foto e o perfil são apagados.</li>
    <li>Registro dos seus aceites e consentimentos: enquanto valerem e mais 5 anos, para provar o que foi aceito.</li>
</ul>

<h2 id="compartilhamento">5. Com quem compartilhamos</h2>
<p>Só com quem precisa para o serviço funcionar, com contrato e dever de sigilo:</p>
<ul>
    <li>hospedagem do sistema e armazenamento de arquivos e cópias de segurança (cifradas);</li>
    <li>bancos que registram boletos e Pix (nome, CPF ou CNPJ, endereço e valor do pagador);</li>
    <li>serviço de envio de e-mail e o WhatsApp, para entregar avisos e atender;</li>
    <li>o próprio condomínio (síndico e conselho), dentro das funções deles;</li>
    <li>autoridades, quando a lei ou um juiz exigir.</li>
</ul>
<p>Alguns desses serviços podem guardar dados fora do Brasil. Nesses casos, usamos fornecedores que oferecem as garantias exigidas pela LGPD (art. 33). Não vendemos dados pessoais.</p>

<h2 id="cookies">6. Cookies</h2>
<p>Cookies são pequenos arquivos que o site grava no seu navegador.</p>
<ul>
    <li><strong>Necessários</strong> (sempre ligados): mantêm a sua sessão, protegem os formulários, lembram a sua escolha sobre cookies e fazem a contagem de visitas do próprio site (um cookie de um dia que só soma visitantes, sem identificar você e sem enviar nada a terceiros).</li>
    <li><strong>Opcionais</strong> (desligados até você aceitar): o mapa do Google na página de contato<?php if (\Source\Services\Privacy\LegalDocuments::analyticsId() !== ''): ?> e a estatística de visitas (Google Analytics, com IP anonimizado)<?php endif; ?><?php if (isset(\Source\Services\Privacy\LegalDocuments::cookieCategories()['ads'])): ?> e os cookies de anúncios e medição de campanhas (<?= htmlspecialchars(substr(\Source\Services\Privacy\LegalDocuments::cookieCategories()['ads'], strlen('Anúncios e medição de campanhas ('), -1)) ?>), que ajudam a saber se uma campanha trouxe visitas<?php endif; ?>.</li>
</ul>
<p>Você pode mudar a sua escolha a qualquer momento pelo link <a href="#cookies" data-cookie-open>Cookies</a>, no rodapé.</p>

<h2 id="direitos">7. Seus direitos (LGPD, art. 18)</h2>
<p>Você pode pedir: confirmação de que tratamos seus dados; acesso a eles; correção; anonimização, bloqueio ou eliminação do que for desnecessário; portabilidade; informação sobre com quem compartilhamos; eliminação dos dados tratados com consentimento; e revisão de decisões automatizadas. Também pode retirar o consentimento.</p>
<p>Peça pelo canal do encarregado (seção 1). Para proteger você, podemos pedir que confirme a sua identidade. Respondemos em até 15 dias. Alguns dados não podem ser apagados enquanto a lei exigir a guarda (por exemplo, a contabilidade do condomínio); nesse caso explicamos o motivo.</p>
<p>Se não ficar satisfeito, você pode reclamar à Autoridade Nacional de Proteção de Dados (ANPD), em <a href="https://www.gov.br/anpd" target="_blank" rel="noopener noreferrer">gov.br/anpd</a>.</p>

<h2 id="criancas">8. Crianças e adolescentes</h2>
<p>O aplicativo e o portal são para maiores de 18 anos. Dados de crianças e adolescentes que moram no condomínio só são cadastrados pelo pai, mãe ou responsável legal, e só o necessário para a vida no condomínio (por exemplo, identificar quem mora na unidade). Não fazemos propaganda para crianças nem usamos esses dados para outra finalidade (LGPD, art. 14).</p>

<h2 id="seguranca">9. Segurança e incidentes</h2>
<p>Usamos conexão cifrada, senhas guardadas de forma irreversível, verificação em duas etapas, controle de acesso por condomínio, registro de quem faz o quê e cópias de segurança cifradas. Se acontecer um incidente com risco ou dano relevante para você, avisaremos a ANPD e as pessoas afetadas no prazo legal.</p>

<h2 id="mudancas">10. Mudanças nesta política</h2>
<p>Quando esta política mudar de forma importante, avisaremos no site e no aplicativo e, quando necessário, pediremos um novo aceite.</p>
