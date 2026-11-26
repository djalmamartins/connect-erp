<?php
/**
 * Modelo de proposta comercial da Connect (ADR 0650), feito a partir do modelo que a Connect usa hoje
 * ("Proposta Comercial Connect Condomínios", 26/11/2026). Renderizado pelo Dompdf: só tabelas, blocos e
 * posição absoluta dentro de cada folha A4 (sem flex/grid). Textos fixos da Connect aqui; os da proposta vêm de $response.
 *
 * @var \Source\Models\Proposal\Proposal $proposal
 * @var \Source\Models\Proposal\ProposalResponse $response
 * @var string $fonts pasta das fontes Montserrat @var string $logo data URI do logo dourado
 * @var string $phone @var string $email
 */
$h = static fn ($text): string => htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
$months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$longDate = static function (?string $date) use ($months): string {
    $time = $date ? strtotime($date) : time();
    $time = $time ?: time();
    return date('d', $time) . ' de ' . $months[(int)date('n', $time)] . ' de ' . date('Y', $time);
};
$paragraphs = static function (?string $text) use ($h): string {
    $blocks = preg_split('/\n\s*\n/', trim((string)$text)) ?: [];
    return implode('', array_map(static fn (string $b): string => '<p>' . nl2br($h(trim($b))) . '</p>', array_filter($blocks, static fn ($b) => trim($b) !== '')));
};
$items = array_values(array_filter(array_map(static fn (string $line): string => trim(preg_replace('/^[\s\-\*•✅✓]+/u', '', $line) ?? '', " \t;."), preg_split('/\r?\n/', (string)$response->scope) ?: []), static fn (string $l): bool => $l !== ''));
$gold = '#c5a131';
$green = '#0f2e26';
$pin = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 34"><path d="M13 1.5C6.6 1.5 1.5 6.6 1.5 12.9c0 8.4 11.5 19.6 11.5 19.6s11.5-11.2 11.5-19.6C24.5 6.6 19.4 1.5 13 1.5z" fill="none" stroke="' . $gold . '" stroke-width="2.6"/><circle cx="13" cy="12.6" r="4.3" fill="none" stroke="' . $gold . '" stroke-width="2.6"/></svg>');
$check = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" rx="3" fill="' . $gold . '"/><path d="M4 8.3l2.6 2.6L12 5.4" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
$icon = static function (string $path) use ($gold): string {
    return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g fill="none" stroke="' . $gold . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</g></svg>');
};
$phoneIcon = $icon('<circle cx="12" cy="12" r="10.5"/><path d="M8.6 7.2l1.6-.4 1 2.4-1.1.9a6.4 6.4 0 0 0 3.8 3.8l.9-1.1 2.4 1-.4 1.6c-.2.6-.8 1-1.4.9A9 9 0 0 1 7.7 8.6c-.1-.6.3-1.2.9-1.4z"/>');
$mailIcon = $icon('<rect x="2.5" y="5" width="19" height="14" rx="1.5"/><path d="M3 6l9 7 9-7"/>');
$webIcon = $icon('<path d="M3 11.5L12 4l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>');
$isPartner = $response->template_type === 'administrator';
$created = $longDate($response->created_at ?: date('Y-m-d'));
$valid = $longDate($response->valid_until);
$header = static function (int $page) use ($h, $pin): string {
    return '<div class="head-line"></div><div class="head-name">Connect Condomínios</div><div class="head-num">' . $page . '</div><img class="corner-pin" src="' . $pin . '" alt="">';
};
$toc = [3 => 'Sobre', 4 => 'Conheça nossos diferenciais', 5 => 'Cronograma de implementação', 6 => $isPartner ? 'Mapa de serviços - parceria' : 'Mapa de serviços - administradora', 7 => 'Formalização', 8 => 'Contato'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title><?= $h('Proposta Comercial Connect Condomínios - ' . $proposal->condominium) ?></title>
<style>
@font-face { font-family: 'Montserrat'; font-weight: normal; font-style: normal; src: url('<?= $h($fonts) ?>/Montserrat-Regular.ttf') format('truetype'); }
@font-face { font-family: 'Montserrat'; font-weight: 600; font-style: normal; src: url('<?= $h($fonts) ?>/Montserrat-SemiBold.ttf') format('truetype'); }
@font-face { font-family: 'Montserrat'; font-weight: bold; font-style: normal; src: url('<?= $h($fonts) ?>/Montserrat-Bold.ttf') format('truetype'); }
@page { margin: 0; size: A4 portrait; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: 'Montserrat', 'DejaVu Sans', sans-serif; color: #2b2f2e; font-size: 8.6pt; line-height: 1.5; }
.page { position: relative; width: 210mm; height: 296.5mm; overflow: hidden; page-break-after: always; }
.page:last-child { page-break-after: auto; }
/* folhas com texto da proposta: crescem para a folha seguinte em vez de cortar o texto longo */
.page.flow { height: auto; overflow: visible; }
.page.flow .inner { position: static; padding: 32mm 20mm 0 22mm; }
.page.flow h1 { margin-bottom: 8mm; }
.inner { position: absolute; left: 22mm; right: 20mm; top: 34mm; }
.head-line { position: absolute; left: 22mm; top: 18mm; width: 58mm; border-top: 0.7pt solid <?= $gold ?>; }
.head-name { position: absolute; left: 118mm; top: 16.4mm; font-size: 5.6pt; color: #555; }
.head-num { position: absolute; left: 180mm; top: 14.6mm; width: 5.4mm; height: 3.9mm; padding-top: 1.5mm; background: <?= $green ?>; color: #fff; font-size: 6pt; font-weight: bold; text-align: center; line-height: 1; }
.corner-pin { position: absolute; left: 197mm; top: 4mm; width: 5.2mm; }
h1 { margin: 0 0 12mm; font-size: 23pt; line-height: 1.18; font-weight: bold; letter-spacing: 0.02em; color: #151a19; text-transform: uppercase; }
h1 span { color: <?= $gold ?>; display: block; }
h3 { margin: 0 0 2mm; font-size: 9.6pt; font-weight: bold; color: #151a19; }
p { margin: 0 0 3.2mm; text-align: justify; }
.small { font-size: 7.4pt; color: #444; }
.gold { color: <?= $gold ?>; }
strong, b { font-weight: bold; }
/* capa */
.cover { background: <?= $green ?>; color: #fff; }
.cover .band { position: absolute; left: 0; top: 0; width: 210mm; height: 205mm; background: #12372d; }
.cover .logo { position: absolute; left: 62mm; top: 62mm; width: 86mm; }
.cover .deco { position: absolute; width: 7mm; opacity: 0.55; }
.cover .rule { position: absolute; left: 22mm; top: 205mm; width: 166mm; border-top: 0.8pt solid <?= $gold ?>; }
.cover .title { position: absolute; left: 22mm; top: 214mm; width: 166mm; font-size: 20pt; font-weight: bold; letter-spacing: 0.04em; text-transform: uppercase; line-height: 1.2; }
.cover .title span { color: <?= $gold ?>; }
.cover .meta { position: absolute; left: 22mm; top: 250mm; width: 166mm; }
.cover .meta td { vertical-align: top; padding: 0; }
.cover .meta img { width: 8mm; }
.cover .meta small { display: block; color: <?= $gold ?>; font-weight: bold; font-size: 7.4pt; }
.cover .meta div { font-size: 8.2pt; color: #fff; margin-bottom: 3mm; }
/* conteúdo */
.toc { margin-top: 18mm; width: 125mm; border-collapse: collapse; }
.toc td { padding: 3.4mm 0; font-size: 11pt; }
.toc .n { width: 13mm; color: <?= $gold ?>; font-weight: bold; }
.toc .t { border-bottom: 0.6pt solid #2b2f2e; }
/* sobre */
.strip { position: absolute; left: 0; top: 0; width: 34mm; height: 296.5mm; background: <?= $green ?>; }
.strip div { position: absolute; top: 0; height: 296.5mm; border-left: 0.6pt solid <?= $gold ?>; opacity: 0.6; }
.about { position: absolute; left: 48mm; right: 18mm; top: 22mm; }
.about h1 { margin-bottom: 7mm; }
.about h3 { margin-top: 3mm; font-size: 8.4pt; }
.sign td { padding: 0; font-size: 6.8pt; color: #444; vertical-align: top; }
/* diferenciais */
.cards { width: 100%; border-collapse: collapse; margin-top: 4mm; }
.cards td { width: 50%; padding: 9mm 6mm 10mm 0; vertical-align: top; }
.cards tr.shade td { background: #f1f1f0; }
.cards tr.shade td:first-child { padding-left: 4mm; }
.cards tr td:first-child { padding-left: 4mm; }
.cards h3 { font-size: 10pt; }
.cards p { font-size: 7.6pt; color: #555; text-align: left; }
/* cronograma */
.steps { width: 100%; border-collapse: collapse; }
.steps td { vertical-align: top; padding: 0 0 6.5mm; }
.steps .ring { width: 15mm; }
.steps .ring div { width: 10mm; height: 10mm; border: 2.2mm solid <?= $gold ?>; border-radius: 50%; }
.steps h3 { margin: 1mm 0 0.6mm; font-size: 9pt; }
.steps p { margin: 0; font-size: 7.6pt; text-align: left; }
/* mapa */
.pillar { margin: 0 0 3mm; }
.pillar b { display: block; font-size: 8.4pt; color: #151a19; }
.list { width: 100%; border-collapse: collapse; margin: 1mm 0 4mm; }
.list td { padding: 0.5mm 0; vertical-align: top; font-size: 8.2pt; }
.list img { width: 3.4mm; margin-top: 0.4mm; }
.value { margin-top: 3mm; padding: 4mm 5mm; background: #f6f3e6; border-left: 1.4mm solid <?= $gold ?>; }
.value small { display: block; color: #7a6520; font-weight: bold; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 1.4mm; }
.value p { margin-bottom: 1.6mm; text-align: left; }
.value p strong { color: #151a19; }
/* formalização */
.formal h3 { margin-top: 3.2mm; font-size: 8.6pt; }
.formal ul { margin: 0 0 3mm 4mm; padding: 0 0 0 3mm; }
.formal li { margin-bottom: 1.2mm; text-align: justify; }
.place { margin-top: 9mm; text-align: right; }
.signature { margin: 16mm auto 0; width: 95mm; border-top: 0.6pt solid #2b2f2e; text-align: center; padding-top: 1.4mm; font-size: 7.4pt; }
.signature small { display: block; color: #666; font-size: 6.8pt; }
.signs td { width: 50%; vertical-align: top; padding: 0 4mm; }
/* contato */
.contact { margin-top: 10mm; border-collapse: collapse; }
.contact td { padding: 0 0 9mm; vertical-align: middle; font-size: 9.4pt; }
.contact .ic { width: 13mm; }
.contact .ic img { width: 7mm; }
.thanks { position: absolute; left: 0; top: 196mm; width: 210mm; height: 100.5mm; background: <?= $green ?>; color: #fff; }
.thanks .logo { position: absolute; left: 22mm; top: 26mm; width: 40mm; }
.thanks .txt { position: absolute; left: 80mm; top: 30mm; width: 110mm; font-size: 15pt; font-weight: bold; line-height: 1.35; }
.thanks .txt span { display: block; color: <?= $gold ?>; font-size: 13pt; margin-top: 3mm; }
</style>
</head>
<body>

<!-- 1. Capa -->
<div class="page cover">
    <div class="band"></div>
    <?php foreach ([[16, 40], [178, 24], [34, 128], [170, 112], [96, 168], [140, 186], [58, 22]] as [$x, $y]): ?>
        <img class="deco" src="<?= $pin ?>" style="left: <?= $x ?>mm; top: <?= $y ?>mm;" alt="">
    <?php endforeach; ?>
    <?php if ($logo !== ''): ?><img class="logo" src="<?= $logo ?>" alt="Connect Condomínios"><?php endif; ?>
    <div class="rule"></div>
    <div class="title">Proposta <span>comercial</span></div>
    <table class="meta"><tr>
        <td style="width: 11mm;"><img src="<?= $pin ?>" alt=""></td>
        <td style="width: 74mm;"><small>Preparado para</small><div><?= $h($proposal->condominium) ?><br><span style="color:#cfd8d4;"><?= $h($proposal->name) ?></span></div></td>
        <td style="width: 11mm;"><img src="<?= $pin ?>" alt=""></td>
        <td><small>Proposta criada em</small><div><?= $h($created) ?></div><small>Proposta válida até</small><div><?= $h($valid) ?></div></td>
    </tr></table>
</div>

<!-- 2. Conteúdo -->
<div class="page">
    <?= $header(2) ?>
    <div class="inner">
        <h1>Conteúdo</h1>
        <table class="toc">
            <?php foreach ($toc as $number => $label): ?><tr><td class="n"><?= sprintf('%02d', $number) ?></td><td class="t"><?= $h($label) ?></td></tr><?php endforeach; ?>
        </table>
    </div>
</div>

<!-- 3. Sobre -->
<div class="page">
    <div class="strip"><div style="left: 9mm;"></div><div style="left: 19mm;"></div><div style="left: 27mm;"></div></div>
    <img class="corner-pin" src="<?= $pin ?>" alt="">
    <div class="about">
        <h1>Sobre a <span>Connect</span><span>Condomínios</span></h1>
        <p>Somos a <b>Connect Condomínios Ltda.</b>, uma empresa preparada para integrar o dia a dia dos condomínios, com o objetivo de facilitar a vida dos moradores, valorizar seus imóveis e oferecer uma administração condominial de excelência. Atuamos fortemente em nossa região desde <b>agosto de 2021</b>.</p>
        <p>Nossa trajetória é marcada pela inovação e pelo constante aprimoramento dos processos e serviços oferecidos aos nossos clientes – síndicos e condôminos. Com mais de 12 anos de experiência em gestão condominial, nosso sócio e síndico profissional Djalma Martins, pós-graduado em Administração Condominial, assegura uma gestão qualificada, estratégica e pautada nas melhores práticas do setor, contribuindo diretamente para elevar nossos serviços ao mais alto padrão do mercado.</p>
        <p>Nosso crescimento e consolidação no setor são resultados do compromisso em buscar o sucesso de nossos clientes e parceiros. Trabalhamos para construir, em cada condomínio que administramos, um ambiente de conforto, segurança e bem-estar.</p>
        <h3>Missão</h3>
        <p>Criar uma experiência positiva na vida dos nossos clientes, oferecendo soluções inovadoras, eficientes e humanizadas.</p>
        <h3>Visão</h3>
        <p>Ser referência na experiência do cliente, combinando excelência em atendimento, tecnologias transformadoras e processos ágeis, por meio de uma equipe qualificada e comprometida com o sucesso do nosso cliente.</p>
        <h3>Valores</h3>
        <p>Ética, Excelência, Tradição e Foco no Cliente.</p>
        <p>Mais do que uma prestação de serviços, entregamos qualidade com transparência e inovação.</p>
        <p><b>Enquanto muitos demoram para responder, nós resolvemos. Atendimento rápido, humano e presente — porque condomínio não pode esperar.</b></p>
        <table class="sign" style="margin-top: 5mm; width: 100%;"><tr>
            <td>Connect Condomínios<br>CRA 03-006019/O</td>
            <td>Responsável Técnico<br>Jordane A. Lopes Pereira<br>CRA 01-067409/D</td>
        </tr></table>
    </div>
</div>

<!-- 4. Diferenciais -->
<div class="page">
    <?= $header(4) ?>
    <div class="inner">
        <h1>Conheça nossos <span>diferenciais</span></h1>
        <table class="cards">
            <tr><td><h3>Prestação de contas</h3><p>Prestação de contas no 5º dia útil.</p></td><td><h3>Pagar e receber</h3><p>Gestão para organizar, orientar e otimizar todas as rotinas do condomínio.</p></td></tr>
            <tr class="shade"><td><h3>Atendimento personalizado</h3><p>Equipe personalizada para orientar sua gestão e os condomínios.</p></td><td><h3>Assessoria especializada</h3><p>Equipes preparadas para prestar assessoria independente do perfil do condomínio.</p></td></tr>
            <tr><td><h3>Central de apoio técnico</h3><p>Uma carteira de empresas e fornecedores de serviços especializada para melhor lhe atender.</p></td><td><h3>Gestão de inadimplência</h3><p>Controle de contas mensal com emissão de boletos para inadimplência no condomínio.</p></td></tr>
        </table>
    </div>
</div>

<!-- 5. Cronograma -->
<div class="page">
    <?= $header(5) ?>
    <div class="inner">
        <h1>Cronograma de <span>implementação</span></h1>
        <table class="steps">
            <?php foreach ([
                ['Envio e aceitação da proposta', 'Recolhimento de informações iniciais e resolução de dúvidas.'],
                ['Levantamento de informações', 'Coleta de documentos do condomínio (regimento interno, atas, contratos vigentes) e reunião inicial com o síndico para alinhar expectativas e pontos críticos.'],
                ['Análise documental', 'Revisão dos documentos e contratos, avaliação do regimento interno, criação de um plano de ação para o condomínio.'],
                ['Transição financeira', 'Transferência de contas bancárias e relatórios financeiros, levantamento de contas a receber e a pagar, atualização dos cadastros de condôminos para emissão de boletos e cobranças.'],
                ['Vistoria e elaboração de planejamento preventivo', 'Realização de uma vistoria completa nas áreas comuns do condomínio, mapeamento de possíveis demandas e criação de um cronograma de manutenção preventiva.'],
                ['Organização dos processos administrativos', 'Estabelecimento de processos para controle de inadimplência, cadastro de fornecedores e definição dos canais de atendimento e comunicação.'],
                ['Plano de comunicação com os condôminos', 'Envio de uma comunicação inicial apresentando a administradora, seus serviços e os principais canais de atendimento.'],
            ] as [$title, $text]): ?>
                <tr><td class="ring"><div></div></td><td><h3><?= $h($title) ?></h3><p><?= $h($text) ?></p></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- 6. Mapa de serviços (textos e valor desta proposta) -->
<div class="page flow">
    <?= $header(6) ?>
    <div class="inner">
        <h1>Mapa de serviços <span><?= $isPartner ? 'Parceria' : 'Administradora' ?></span></h1>
        <?= $paragraphs($response->introduction) ?>
        <p>Nosso trabalho é baseado em três pilares essenciais:</p>
        <div class="pillar"><b>Comunicação</b>Mantemos um atendimento ágil, claro e acessível, facilitando o contato entre síndico, moradores e administradora. Todas as informações são repassadas de forma simples e objetiva.</div>
        <div class="pillar"><b>Transparência</b>Realizamos uma gestão financeira clara, com prestação de contas mensal e acesso às informações sempre que necessário, garantindo segurança e confiança para todos.</div>
        <div class="pillar"><b>Parceria</b>Atuamos lado a lado com o síndico e o conselho, apoiando nas decisões e buscando sempre as melhores soluções para o condomínio.</div>
        <?php if ($items !== []): ?>
            <p style="margin-top: 3mm;"><b>Principais atividades:</b></p>
            <table class="list">
                <?php foreach ($items as $item): ?><tr><td style="width: 6mm;"><img src="<?= $check ?>" alt=""></td><td><?= $h($item) ?></td></tr><?php endforeach; ?>
            </table>
        <?php endif; ?>
        <div class="value">
            <small>Valor da proposta</small>
            <?= $paragraphs($response->commercial_terms) ?>
            <?php if ($response->payment_terms): ?><p><strong>Pagamento:</strong> <?= nl2br($h($response->payment_terms)) ?></p><?php endif; ?>
            <p><strong>Validade:</strong> até <?= $h($valid) ?>.</p>
        </div>
        <?php if ($response->notes): ?><div style="margin-top: 4mm;"><p><b>Observações</b></p><?= $paragraphs($response->notes) ?></div><?php endif; ?>
    </div>
</div>

<!-- 7. Formalização -->
<div class="page flow">
    <?= $header(7) ?>
    <div class="inner formal">
        <h1>Formalização <span>e validação</span></h1>
        <p>A Connect Condomínios, inscrita no CNPJ sob o nº 43.346.552/0001-69, com sede em Travessa Comendador Lalão, 10, Sala 401 - Conselheiro Lafaiete - MG, 36400-040, apresenta a <?= $h($proposal->condominium) ?> a seguinte proposta para prestação de serviços de administração condominial.</p>
        <h3>1. Objetivo da proposta</h3>
        <p>Esta proposta tem como objetivo estabelecer os serviços de administração condominial a serem realizados pela Connect Condomínios e formalizar o acordo entre as partes para o gerenciamento das necessidades administrativas, financeiras, operacionais e de manutenção do condomínio.</p>
        <h3>2. Prazo de vigência</h3>
        <p>Este contrato terá início na assinatura desta proposta e vigência por período de um ano a contar da data de assinatura, podendo ser renovado automaticamente por períodos iguais, salvo manifestação em contrário de uma das partes, por escrito, com trinta dias de antecedência.</p>
        <h3>3. Obrigações das partes</h3>
        <ul>
            <li><b>Obrigações da Administradora:</b> realizar todos os serviços especificados de forma eficiente e transparente, garantindo o cumprimento das obrigações legais e administrativas do condomínio.</li>
            <li><b>Obrigações do Condomínio:</b> fornecer as informações necessárias para a execução dos serviços e efetuar o pagamento dos valores acordados na data estabelecida.</li>
            <li>Em caso de contratação do síndico profissional, a formalização é feita mediante ata de eleição.</li>
        </ul>
        <h3>4. Assinatura e aceitação</h3>
        <p>Ao assinar este documento, as partes concordam com os termos e condições estabelecidos nesta proposta, comprometendo-se a honrar suas respectivas obrigações.</p>
        <p class="place">_________________________, ____ de ________________ de ________</p>
        <table class="signs" style="width: 100%; margin-top: 6mm;"><tr>
            <td><div class="signature" style="width: auto;">Connect Condomínios<small>CNPJ 43.346.552/0001-69</small></div></td>
            <td><div class="signature" style="width: auto;"><?= $h($proposal->condominium) ?><small>Representante legal</small></div></td>
        </tr></table>
        <p class="small" style="margin-top: 8mm; text-align: center;">Protocolo <?= $h($proposal->protocol) ?> · versão <?= (int)$response->id ?> · proposta válida até <?= $h($valid) ?></p>
    </div>
</div>

<!-- 8. Contato -->
<div class="page">
    <?= $header(8) ?>
    <div class="inner">
        <h1>Resolva <span>rápido</span></h1>
        <table class="contact">
            <tr><td class="ic"><img src="<?= $phoneIcon ?>" alt=""></td><td><?= $h($phone) ?></td></tr>
            <tr><td class="ic"><img src="<?= $mailIcon ?>" alt=""></td><td><?= $h($email) ?></td></tr>
            <tr><td class="ic"><img src="<?= $webIcon ?>" alt=""></td><td>www.connectcondominios.com.br</td></tr>
            <tr><td class="ic"><img src="<?= $pin ?>" alt="" style="width: 7mm;"></td><td>Travessa Comendador Lalão, 10<br>Sala 401 - Centro<br>Conselheiro Lafaiete - MG</td></tr>
        </table>
    </div>
    <div class="thanks">
        <?php if ($logo !== ''): ?><img class="logo" src="<?= $logo ?>" alt="Connect Condomínios"><?php endif; ?>
        <div class="txt">Obrigado pela oportunidade e aguardamos seu contato!<span><?= $h($phone) ?></span></div>
    </div>
</div>

</body>
</html>
