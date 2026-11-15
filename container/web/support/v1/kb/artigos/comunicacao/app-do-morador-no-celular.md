---
codigo:
titulo: App do morador no celular
resumo: O app Connect Morador (iPhone e Android) mostra ao morador os boletos, a prestação de contas, os comunicados, as reservas, as ocorrências, a portaria, os documentos e as assembleias da unidade dele, só com as funções que o condomínio ligou.
categoria: comunicacao
icone: phone-portrait-outline
situacao: guia
palavras: esqueci a senha, recuperar senha, foto do perfil, boas-vindas, primeiro acesso, aplicativo, app, celular, morador, iphone, android, boleto, segunda via, reservas, encomendas, visitantes, face id, digital, biometria
rascunho: nao
---

O **Connect Morador** é o aplicativo de celular dos moradores. Ele usa os mesmos dados do ERP e as mesmas regras do app do morador na web: o morador só vê a própria unidade, e só aparecem as funções que o condomínio ligou.

## Quem pode entrar

1. A administração convida o morador pelo cadastro de moradores. O convite chega por e-mail e vale 7 dias.
2. O morador cria a senha pelo link do convite.
3. No app, ele entra com o e-mail (ou CPF) e a senha. A verificação em duas etapas é opcional para o morador: só se ele tiver ligado, o app pede o código do autenticador.
4. Se o celular tiver Face ID, Touch ID ou digital cadastrada, aparece a opção **Nas próximas vezes, entrar com Face ID** (ou digital). Marcada, o app pede a biometria logo depois de entrar e, nas próximas vezes, abre só com ela, sem digitar a senha. O e-mail usado fica preenchido no login.
5. Esqueceu a senha? No login, {{Esqueci minha senha}} pede o e-mail ou CPF e manda um link para o e-mail cadastrado. A senha nova é criada pelo link, como na web. Por segurança, o app sempre mostra a mesma mensagem, exista a conta ou não, e aceita poucas tentativas seguidas.

## Primeiro acesso

Na primeira vez que o morador entra, o app abre as **boas-vindas**:

1. Ele pode colocar uma foto no perfil: {{Tirar foto}} usa a câmera e {{Escolher da galeria}} usa as fotos do celular. A foto é recortada em quadrado. Também dá para deixar para depois.
2. Depois vêm cinco dicas curtas, uma para cada parte do app: Início, Resolva fácil, Unidade, Notificações e Perfil. {{Pular}} vai direto para o Início.

As boas-vindas aparecem só uma vez por conta, mesmo que o morador tenha mais de uma unidade ou troque de celular. Para ver as dicas de novo, é só ir em **Perfil > Rever as dicas do app**.

> Quem deixa de ser morador (vínculo encerrado) perde o acesso ao app na hora, sem precisar fazer nada no celular.

## O que o morador vê

As telas seguem o jeito do iPhone: o nome da tela aparece grande no topo e vira uma barra pequena quando a pessoa rola. Embaixo fica uma barra flutuante e translúcida com **Início**, **Resolva fácil**, **Unidade** e **Perfil**. Nas telas abertas a partir delas, o botão redondo no canto de cima volta. Para atualizar uma tela, basta puxá-la para baixo: no iPhone aparece a logo da Connect, que estica conforme a pessoa puxa e fica quicando enquanto carrega. As confirmações e os avisos (cancelar reserva, pedido enviado, erro ao abrir um arquivo) aparecem numa janela do próprio app, com ícone e botão na cor da ação, e o "copiado" aparece como um recado rápido no topo, que some sozinho.

- **Início**: a saudação com a data, o sino de notificações (com o número de avisos novos) ao lado do perfil, um cartão com o ícone da Connect, o condomínio, a unidade, o valor em aberto, o próximo vencimento e, quando a próxima conta já tem boleto, os botões {{2ª via do boleto}} e {{Copiar código}}. O olho ao lado de "Em aberto" esconde os valores, e quem tem mais de uma unidade troca tocando no nome do condomínio. Abaixo ficam até seis botões grandes, dois por linha, com os serviços principais (Boletos, Reservas, Chamados, Autorizar entrada, Documentos e Prestação de contas, só os que o condomínio ligou) e as últimas notificações, com {{Ver todas}}.
- **Boletos**: o total em aberto e os boletos a pagar, cada um com o ícone da situação e os botões {{2ª via (PDF)}}, {{Copiar código}} e {{Pix}}. Embaixo fica o histórico: o app mostra os meses mais recentes e busca os anteriores conforme a pessoa rola a tela, sem baixar tudo de uma vez.
- **Prestação de contas**: fica no **Resolva fácil** e nos botões do Início, e mostra para onde foi o dinheiro do condomínio mês a mês.
- **Reservas**: duas abas. Em **Nova reserva** ficam os espaços liberados (com filtros Todos, Gratuitos e Com taxa), cada um com preço, capacidade e horário. Tocando no espaço abre o calendário do mês: dias livres em branco, parte do dia tomada em amarelo, ocupados em vermelho e fora do prazo em cinza. O morador escolhe o dia, vê os horários já reservados (sem saber quem reservou), escolhe o horário e pede a reserva, ou entra na lista de espera. Todo pedido do morador vai para a avaliação do síndico: o app avisa "Pedido enviado para o síndico" e a reserva fica **Aguardando o síndico**. Quando a equipe aprova no ERP, o morador recebe o e-mail de reserva aprovada e, ao abrir o app, vê o aviso **Reserva aprovada** no Início. Em **Minhas reservas** ficam as reservas com a cor da situação (aprovada, aguardando o síndico, cancelada) e a lista de espera.
- **Ocorrências**: registrar uma ocorrência com assunto, descrição e prioridade e acompanhar a resposta.
- **Resolva fácil**: Boletos, Reservas, Chamado, Ocorrência, Documentos, Prestação de contas, Assembleia digital, Comunicados e CND. Chamado e CND aparecem como "Em breve" até entrarem no app.
- **Unidade**: a unidade e o condomínio (com troca, para quem tem mais de uma), Moradores ("Em breve"), Autorizar entrada (visitantes), Veículos, Pets, Correspondências (encomendas) e Consultar chamados.
- **Perfil**: foto, nome e unidade. Tocando na foto, dá para tirar outra, escolher da galeria ou remover. Também tem Rever as dicas do app, Verificação em duas etapas, entrada com Face ID ou digital, trocar de unidade e sair.
- **Notificações**: o sino abre a lista de avisos (contas vencidas, encomendas, visitas do dia, comunicados, assembleias, reservas chegando e reservas aprovadas); tocando num aviso, abre a tela dele.

## Ligar e desligar funções

Cada função é ligada por condomínio na matriz do portal do morador. Tudo começa desligado. Uma função desligada some do menu do app e, se o morador tentar abrir por um atalho antigo, vê o aviso "Função desligada".

## Segurança

- A senha e o limite de tentativas são os mesmos do login na web.
- O app guarda só um código de acesso no cofre do celular (Keychain no iPhone, Keystore no Android). Ele vale 90 dias e cai com 30 dias sem uso.
- A biometria é a do próprio celular: o app não vê nem guarda a digital ou o rosto, só recebe do sistema se é o dono do aparelho. Ela só destrava o código de acesso que já está no cofre; a senha nunca fica guardada no celular.
- Com a biometria ligada, o app pede de novo ao abrir e quando volta depois de 5 minutos fora. Na tela de entrada rápida, {{Entrar com e-mail e senha}} sai do aparelho e volta para o login normal.
- Quando o morador escolhe "sair de todos os aparelhos" na web, o app também sai.
- Cada entrada e saída pelo app fica na auditoria, com o nome do aparelho.
- A foto do perfil fica guardada fora da área pública do servidor, sem os dados de localização da imagem, e só abre para quem está logado.

>! O app ainda não está nas lojas da Apple e do Google. Enquanto isso, o teste é feito pelo Expo Go, com a equipe da Connect.
