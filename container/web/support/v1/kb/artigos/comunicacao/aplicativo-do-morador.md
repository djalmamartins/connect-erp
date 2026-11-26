---
codigo:
titulo: Aplicativo do morador
resumo: O que o morador vê no aplicativo app.connect.lab (boletos, reservas, encomendas, visitantes, comunicados e mais), como ele entra pelo convite e como ligar cada função no condomínio.
categoria: comunicacao
rota:
menu:
icone: phone-portrait-outline
situacao: guia
relacionados: criacao-de-usuarios-para-moradores, permissoes-de-moradores, disco-virtual, dados-pessoais
palavras: aplicativo, app, morador, termos, lgpd, privacidade, meus dados, exclusão, novidades, portal, celular, instalar, segunda via, reserva, encomenda, visitante, duas etapas, 2fa, segurança, recibo, edital, sininho, avisos, busca
rascunho: nao
---

## Para que serve

O aplicativo do morador é o site **app.connect.lab**, feito para o celular. O morador vê as coisas da **própria unidade**: boletos e segunda via, prestação de contas, comunicados, reservas das áreas comuns, encomendas na portaria, visitantes, ocorrências, assembleias e documentos do condomínio. Ele pode instalar o site na tela inicial do celular como um aplicativo.

## Como o morador entra

1. A administração manda o convite em [[Configurações > Acesso > Criação de usuários para moradores]].
2. O morador abre o e-mail, cria a senha, marca que leu e aceita os **Termos de Uso** e a **Política de Privacidade** (sem isso o convite não é aceito) e entra em **app.connect.lab** com o e-mail e a senha. A verificação em duas etapas **não é obrigatória** para o morador: ele entra só com e-mail e senha.
3. Quem tem mais de uma unidade troca de unidade no alto da tela.

>! Só entra quem tem **vínculo ativo** com uma unidade: cadastro de morador ativo, unidade e condomínio ativos e dentro das datas de início e fim. Quem já está logado no ERP com uma conta da equipe, sem unidade, volta para a tela de entrada do aplicativo com um aviso de que essa conta não é de morador, e não vê nenhum dado.

## Verificação em duas etapas (opcional)

O morador não mexe com o dinheiro do condomínio, por isso a verificação em duas etapas é **opcional** para ele, mesmo quando é obrigatória para a equipe. Se quiser mais proteção, ele liga em **Perfil > Verificação em duas etapas**: lê o QR code com um aplicativo autenticador (Google Authenticator, Microsoft Authenticator ou outro), digita o código de 6 números e guarda os códigos de recuperação. No autenticador, a conta aparece como **Connect Condomínios**. Daí em diante o código é pedido ao entrar, no site e no aplicativo do celular. Para desligar, ele digita o código atual na mesma tela.

>! Quem é da equipe e também mora no condomínio continua com a verificação obrigatória, porque a mesma conta entra no ERP.

## Ligar as funções no condomínio

Tudo começa **desligado**. Em [[Configurações > Acesso > Permissões de Moradores]], escolha o condomínio na barra superior e ligue o que o aplicativo deve mostrar: Boletos, Prestação de contas, Comunicados, Reservas, Ocorrências, Encomendas, Visitantes, Assembleias, Documentos e Moradores da unidade. A mudança vale na hora.

> Boletos aparecem só para **proprietário, inquilino e responsável financeiro**. O morador sem responsabilidade financeira não vê cobrança.

>> Tudo sobre [Permissões de Moradores](artigo:permissoes-de-moradores)

## O que o morador pode fazer

- **Menu de baixo** (no celular): **Início**, **Resolva fácil**, **Unidade** e **Perfil**. No computador, as mesmas opções ficam no menu lateral.
- **Início**: o cartão verde mostra o valor em aberto, o próximo vencimento e os botões **2ª via do boleto** e **Copiar código**. O ícone de olho esconde os valores (a escolha fica no aparelho). Quem tem mais de uma unidade toca na setinha ao lado do nome do condomínio para trocar. Embaixo ficam até seis botões, dois por linha: Boletos, Reservas, Chamados, Autorizar entrada, Documentos e Prestação de contas. Depois deles vêm as **notificações** mais recentes.
- **Resolva fácil**: os serviços do condomínio num lugar só: Documentos, Prestação de contas, Assembleia digital, Boletos, Reservas, CND, Chamado, Ocorrência e Comunicados.
- **Unidade**: atalhos para Moradores, Correspondências (encomendas), Autorizar entrada (visitantes) e Chamados, e a lista de veículos e pets.
- **Moradores** (função **Moradores da unidade**): mostra quem está ligado à unidade, com o vínculo e a situação, sem CPF, e-mail ou telefone.
  - O **proprietário** cadastra inquilino e morador e pode **bloquear** um inquilino. O inquilino bloqueado fica inativo, com fim do vínculo no dia, e não entra mais no aplicativo. Logo depois, o app oferece cadastrar o novo inquilino.
  - O **inquilino** cadastra morador (família ou quem mora junto), mas não bloqueia ninguém.
  - O **morador** só vê a lista.
  - O cadastro entra em [[Cadastros > Proprietários e inquilinos]] com a observação "Cadastrado pelo aplicativo do morador". Para a pessoa nova usar o aplicativo, a administração envia o convite, como sempre. Tudo fica na auditoria.
- **Perfil**: os dados da pessoa no cadastro (nome, e-mail, telefone e CPF mascarado), a **foto** (colocar, trocar ou remover), a verificação em duas etapas, as dicas do primeiro acesso, os avisos, como instalar no celular e o botão **Sair**.
- **Primeiro acesso**: na primeira vez que entra, o morador vê as **boas-vindas**: coloca uma foto (tirada na hora ou escolhida da galeria) e passa por três dicas rápidas. Ele pode pular a qualquer momento, e as boas-vindas não aparecem de novo. A foto é recortada em quadrado, perde os dados de localização e só a própria pessoa vê.
- **Esqueci minha senha**: na tela de entrada, o morador informa o e-mail ou o CPF e recebe um link para criar uma senha nova. A resposta é sempre a mesma, exista a conta ou não, e há limite de pedidos.
- **Barra de cima**: o nome da pessoa e a unidade, a **busca**, o **sininho** ao lado da foto do perfil (comunicados, assembleias, documentos, encomendas e páginas). O sininho mostra os avisos que pedem atenção: conta vencida, encomenda na portaria, visita de hoje, comunicado novo, assembleia convocada, reserva da semana e cada andamento dos chamados que a pessoa abriu (abertura, resposta, situação, equipe e responsável; nota interna da equipe nunca aparece). Tocando no aviso do chamado, abre a conversa. O número vermelho mostra quantos avisos chegaram desde a última vez que ele abriu o sininho.
- **Boletos**: ver a situação de cada conta da unidade; ao rolar até o fim, as contas mais antigas carregam sozinhas, de 6 em 6 meses. Tocando na conta: se estiver paga, abre o **recibo** para imprimir ou salvar em PDF; se estiver em aberto, oferece **baixar o boleto**, copiar a linha digitável ou o Pix.
- **Prestação de contas**: abrir os links publicados no fechamento do mês.
- **Comunicados**: ler os avisos gerais e os enviados para a unidade dele.
- **Reservas**: na aba **Nova reserva**, escolher o espaço (filtro Gratuitos ou Com taxa) e ver o **calendário do mês** com dias livres, de parte do dia e ocupados; tocando no dia aparecem os horários já tomados (sem dizer de quem), o pedido e a lista de espera. Na aba **Minhas reservas**, acompanhar e cancelar uma reserva futura. Valem as mesmas regras da tela de Reservas do ERP (horário, conflito, aprovação). Reserva com taxa já lançada não pode ser cancelada pelo aplicativo.
- **Encomendas**: ver o que chegou na portaria e o que já foi retirado.
- **Visitantes**: avisar a portaria de uma visita e cancelar o aviso. O documento do visitante aparece mascarado.
- **CND** (com a função Boletos): o morador **pede** a certidão negativa de débitos à administradora, que emite em [[Documentos > Certidão de Débitos]]:
  - o pedido chega como chamado do Help Desk (**Pedido de CND**); pedir de novo com um pedido aberto não duplica;
  - ao ser emitida, a certidão aparece na lista do app com o PDF e o código de verificação, e o chamado é respondido;
  - com conta vencida em aberto, não há pedido nem certidão: a tela só informa o débito e leva aos boletos.

  A lista mostra as certidões negativas da unidade dos últimos 12 meses. Como a tela mostra débitos, só proprietário, inquilino e responsável financeiro a veem.
- **Chamados e ocorrências**: o morador escolhe o que quer registrar:
  - **Chamado** é um pedido ou uma dúvida para a administração (por exemplo, a cópia do regimento);
  - **Ocorrência** é um problema no condomínio (barulho, vazamento, dano).

  Os dois vão para o SAC, com a categoria Chamado ou Ocorrência, e o morador vê o **número**, a situação e **conversa** com a administração até o registro ser fechado. A lista mostra os dois, com o filtro Todos, Chamados e Ocorrências. Em Resolva fácil, os botões Chamado e Ocorrência já abrem o formulário com o tipo marcado.
- **Assembleias**: ver a pauta, **baixar o edital de convocação** (para imprimir ou salvar em PDF), o resultado de cada item e a ata depois de encerrada.
- **Documentos**: os arquivos que a administração marcou como visíveis no Disco Virtual. Tocando no documento (por exemplo, o regimento interno), o PDF abre numa aba para ler; quem quiser guardar salva por ali. Outros tipos de arquivo continuam sendo baixados.

## Mostrar documentos aos moradores

No [[Documentos > Disco Virtual]], clique em {{Mostrar}} na linha do arquivo. O arquivo ganha a etiqueta **Visível aos moradores**; para tirar, clique em {{Esconder}}. Arquivo bloqueado pelo antivírus não pode ser baixado pelo morador.

>> Tudo sobre o [Disco Virtual](artigo:disco-virtual)

> O recibo é montado com a baixa que a administração registrou no ERP. Conta sem baixa não tem recibo.

## Segurança e privacidade

- O morador vê só a unidade dele, nunca a de outro morador.
- Toda ação (reserva, aviso de visita, ocorrência, download de boleto ou documento) fica registrada na auditoria.
- O aplicativo não guarda dados no celular; sem internet aparece só a página "Sem conexão".

## Termos, novidades e pedidos sobre os dados (LGPD)

- **Aceite dos termos.** No primeiro acesso, e sempre que a administradora publicar uma versão nova dos Termos de Uso ou da Política de Privacidade, o morador vê a tela **Termos e privacidade** antes de qualquer outra. Ele só segue depois de marcar que leu e aceita. O aceite fica registrado com a versão, a data e por onde foi feito (convite, aplicativo ou celular).
- **Novidades.** Receber novidades da administradora é **opcional** e separado do aceite. O morador liga ou desliga em **Perfil > Privacidade e meus dados**. Avisos do condomínio (boletos, assembleias, encomendas) continuam chegando de qualquer forma.
- **Pedidos sobre os dados.** Em **Perfil > Privacidade e meus dados**, o morador pede acesso, correção, exclusão, cópia (portabilidade) ou com quem os dados foram compartilhados. O pedido vira um **chamado da categoria LGPD**, com prioridade alta, em [[Operacional > Rotina > Chamados]]. A equipe responde em até **15 dias** e usa [[Configurações > Acesso > Dados pessoais (LGPD)]] para o relatório ou a anonimização. Dados que a lei manda guardar (por exemplo, a contabilidade do condomínio) não podem ser apagados; nesse caso, a resposta explica o motivo.
