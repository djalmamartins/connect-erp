---
codigo:
titulo: Aplicativo do morador
resumo: O que o morador vê no aplicativo app.connect.lab (boletos, reservas, encomendas, visitantes, comunicados e mais), como ele entra pelo convite e como ligar cada função no condomínio.
categoria: comunicacao
rota:
menu:
icone: phone-portrait-outline
situacao: guia
relacionados: criacao-de-usuarios-para-moradores, permissoes-de-moradores, disco-virtual
palavras: aplicativo, app, morador, portal, celular, instalar, segunda via, reserva, encomenda, visitante, duas etapas, 2fa, segurança
rascunho: nao
---

## Para que serve

O aplicativo do morador é o site **app.connect.lab**, feito para o celular. O morador vê as coisas da **própria unidade**: boletos e segunda via, prestação de contas, comunicados, reservas das áreas comuns, encomendas na portaria, visitantes, ocorrências, assembleias e documentos do condomínio. Ele pode instalar o site na tela inicial do celular como um aplicativo.

## Como o morador entra

1. A administração manda o convite em [[Configurações > Acesso > Criação de usuários para moradores]].
2. O morador abre o e-mail, cria a senha e entra em **app.connect.lab** com o e-mail e a senha. A verificação em duas etapas **não é obrigatória** para o morador: ele entra só com e-mail e senha.
3. Quem tem mais de uma unidade troca de unidade no alto da tela.

>! Só entra quem tem **vínculo ativo** com uma unidade: cadastro de morador ativo, unidade e condomínio ativos e dentro das datas de início e fim. Funcionário da administradora sem vínculo recebe a mensagem e não vê nada.

## Verificação em duas etapas (opcional)

O morador não mexe com o dinheiro do condomínio, por isso a verificação em duas etapas é **opcional** para ele, mesmo quando é obrigatória para a equipe. Se quiser mais proteção, ele liga em **Minha unidade > Segurança da conta**: lê o QR code com um aplicativo autenticador (Google Authenticator, Microsoft Authenticator ou outro), digita o código de 6 números e guarda os códigos de recuperação. Daí em diante o código é pedido ao entrar, no site e no aplicativo do celular. Para desligar, ele digita o código atual na mesma tela.

>! Quem é da equipe e também mora no condomínio continua com a verificação obrigatória, porque a mesma conta entra no ERP.

## Ligar as funções no condomínio

Tudo começa **desligado**. Em [[Configurações > Acesso > Permissões de Moradores]], escolha o condomínio na barra superior e ligue o que o aplicativo deve mostrar: Boletos, Prestação de contas, Comunicados, Reservas, Ocorrências, Encomendas, Visitantes, Assembleias e Documentos. A mudança vale na hora.

> Boletos aparecem só para **proprietário, inquilino e responsável financeiro**. O morador sem responsabilidade financeira não vê cobrança.

>> Tudo sobre [Permissões de Moradores](artigo:permissoes-de-moradores)

## O que o morador pode fazer

- **Boletos**: ver a situação de cada conta da unidade e baixar o PDF do boleto.
- **Prestação de contas**: abrir os links publicados no fechamento do mês.
- **Comunicados**: ler os avisos gerais e os enviados para a unidade dele.
- **Reservas**: pedir reserva de uma área comum, entrar na lista de espera e cancelar uma reserva futura. Valem as mesmas regras da tela de Reservas do ERP (horário, conflito, aprovação). Reserva com taxa já lançada não pode ser cancelada pelo aplicativo.
- **Encomendas**: ver o que chegou na portaria e o que já foi retirado.
- **Visitantes**: avisar a portaria de uma visita e cancelar o aviso. O documento do visitante aparece mascarado.
- **Ocorrências**: registrar uma ocorrência para a administração.
- **Assembleias**: ver a pauta, o resultado de cada item e a ata depois de encerrada.
- **Documentos**: baixar os arquivos que a administração marcou como visíveis no Disco Virtual.

## Mostrar documentos aos moradores

No [[Documentos > Disco Virtual]], clique em {{Mostrar}} na linha do arquivo. O arquivo ganha a etiqueta **Visível aos moradores**; para tirar, clique em {{Esconder}}. Arquivo bloqueado pelo antivírus não pode ser baixado pelo morador.

>> Tudo sobre o [Disco Virtual](artigo:disco-virtual)

## Segurança e privacidade

- O morador vê só a unidade dele, nunca a de outro morador.
- Toda ação (reserva, aviso de visita, ocorrência, download de boleto ou documento) fica registrada na auditoria.
- O aplicativo não guarda dados no celular; sem internet aparece só a página "Sem conexão".
