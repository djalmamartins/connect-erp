---
codigo: CN-004
titulo: Abrir e acompanhar chamados
resumo: Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do SAC, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.
categoria: operacional
rota: /operacional/chamados
menu: Agenda > Atendimento > Chamados
icone: list-outline
situacao: funciona
relacionados: pedir-ajuda, ocorrencias, vistorias, manutencoes, chamados-do-whatsapp
palavras: chamados, operacional, CN-004, help desk, protocolo, suporte, atendimento, SAC, OCO, número do chamado, equipe, administrativo, financeiro, manutenção, notificação do morador, aviso no app
rascunho: nao
---

Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do SAC, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.

## Onde fica

No menu lateral, abra [[Agenda > Atendimento > Chamados]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela chamados
rota: /operacional/chamados
condominio: residencial-jardim-das-acacias
legenda: Tela Chamados
1: menu | Chamados no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Abrir chamado") | Botão {{Abrir chamado}}
3: main .erp-page-head__actions :is(a,button):has-text("Fila do SAC") | Botão {{Fila do SAC}}
4: main :is(form[method=get], .erp-filters) | Filtros: busca, condomínio, prioridade, responsável, prazo, origem e tipo
5: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Fila do SAC}}: abre a fila de atendimento no SAC (para quem atende os chamados).
- {{Abrir chamado}}: abre o formulário de abertura numa janela por cima da fila.

## Abrir chamado

O chamado cai na fila do SAC e a equipe é avisada na hora.

1. Clique em {{Abrir chamado}}, no alto da tela.
2. Preencha **Assunto** e **Descrição**.
3. Clique em {{Enviar para o SAC}}.

> Enquanto você digita o assunto, a tela sugere artigos desta Central de Ajuda que falam do mesmo tema. Se um deles resolver, não precisa abrir o chamado; se não, siga normalmente.

```tela chamados-form
rota: /operacional/chamados
condominio: residencial-jardim-das-acacias
legenda: Abrir chamado
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Abrir chamado")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="condominium_id"] | Condomínio
2: dialog[open] [name="unit_id"] | Unidade
3: dialog[open] [name="subject"] | Assunto (obrigatório)
4: dialog[open] [name="message"] | Descrição (obrigatório)
5: dialog[open] [name="area"] | Área
6: dialog[open] button:has-text("Enviar para o SAC") | Botão {{Enviar para o SAC}}
```

### O que preencher

- **Condomínio**.
- **Unidade**: A unidade precisa ser do condomínio escolhido.
- **Assunto** (obrigatório).
- **Descrição** (obrigatório).
- **Área**.
- **Prioridade**: Opções: Baixa · até 120 h, Média · até 72 h, Alta · até 48 h e Urgente · até 24 h.
- **Nome do solicitante**.
- **E-mail do solicitante**.
- **Telefone**: Só quando o pedido é de outra pessoa.

## Acompanhar e responder

Clique no protocolo na lista para abrir o chamado. A tela é igual à do SAC: à esquerda fica a conversa, com o pedido no alto e cada resposta embaixo; à direita ficam os detalhes do chamado (situação, prioridade, condomínio, unidade, prazo e quem pediu). Para responder, escreva no campo de resposta e envie: se o chamado estava aguardando você, ele volta para a fila. Quando a equipe responde, o sino da barra superior avisa e leva direto ao chamado.

## Pesquisar e filtrar

Toda ocorrência também é um chamado. No filtro de tipo, **Só ocorrências** mostra apenas elas, e **Sem as ocorrências** mostra os outros chamados. O selo "Ocorrência #" na lista leva à ocorrência.

No alto da tela ficam os contadores da fila, como no SAC: um por situação, **SLA vencido**, **Vence hoje** e **Sem responsável**. Clique em um deles para filtrar pela situação. Logo abaixo fica a barra de filtros, com a busca por protocolo ou assunto, e as colunas da lista são as mesmas da fila do SAC.

Use os filtros no alto da lista: **Buscar**, **Condomínio**, **Prioridade**, **Responsável**, **Prazo**, **Origem** e **Tipo**. A situação se escolhe pelos contadores.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

As conversas do WhatsApp atendidas no Connect Talk também viram chamado, com a origem **WhatsApp (Connect Talk)**: veja [Chamados que chegam pelo WhatsApp](artigo:chamados-do-whatsapp).

## O que a lista mostra

Colunas: **Protocolo**, **Atualizado**, **Solicitante** (com condomínio, unidade e origem), **Assunto**, **Situação**, **Responsável**, **Prioridade** e **SLA** (o prazo, ou "Vencido" em vermelho).

## Atender pelo ERP

Quem faz parte da equipe de atendimento usa no ERP os mesmos recursos do SAC:

- **Cronômetro**: ao abrir o chamado, ele começa a contar o tempo dedicado. Dá para pausar ou zerar. O tempo é somado ao chamado quando você envia a mensagem.
- **Responder** ou **Nota interna**: a nota interna só a equipe vê.
- **Resposta rápida**, **Anexos** e **Resolver após enviar**.
- **Triagem** (à direita, em **Propriedades do chamado**): situação, categoria, prioridade, responsável, equipe e tags, e o botão **Resolver chamado** (ou **Reabrir chamado**). A **Equipe** é escolhida numa lista: **Administrativo**, **Financeiro**, **Manutenção** ou **Geral** (ou **Sem equipe**). Escolha e clique em {{Salvar alterações}}. Um chamado antigo com outra equipe escrita à mão mostra esse nome com "(antiga)" até alguém escolher uma da lista.
- **Detalhes do chamado**: protocolo, origem, condomínio, unidade, quem pediu, primeira resposta, prazo SLA, tempo dedicado e número de respostas.
- **Histórico de alterações**: quem mudou o quê e quando.
- Na lista, marque vários chamados e escolha a situação em **Com os selecionados**, à direita da barra de filtros.

## O morador acompanha pelo app

Quando o chamado é de um morador com acesso ao app, cada movimentação da equipe vira um aviso no sino do app (no celular e no navegador) e, se ele usa o app no celular, também uma notificação na tela do aparelho:

- a abertura, com o número do protocolo;
- cada resposta enviada para ele;
- a troca de situação (em atendimento, aguardando o morador, resolvido, fechado, reaberto);
- a escolha da equipe e do responsável (aparece só o primeiro nome de quem atende).

Várias mudanças salvas de uma vez viram um aviso só. A **Nota interna** nunca chega ao morador, e prioridade, categoria e tags também não, porque são da triagem da equipe. Esses avisos não vão por e-mail: o morador recebe por e-mail só os avisos da cobrança, a prestação de contas, o convite e a troca de senha.

> Se o pedido é de um morador, marque **Um morador ou outra pessoa** e informe nome, e-mail e telefone dele: a resposta vai para ele.

> Guarde o protocolo: ele identifica o chamado em qualquer conversa.

## Como é o número do protocolo

O protocolo tem as letras do tipo, a data de abertura e a ordem do dia, igual ao do Connect Talk:

- **SAC202611160008**: chamado (SAC, site, aplicativo, WhatsApp), o 8º aberto em 16/11/2026;
- **OCO202611160003**: ocorrência, a 3ª registrada em 16/11/2026.

Chamados e ocorrências contam separado, e a contagem recomeça todo dia. Na busca, pode digitar do jeito que estiver anotado, com espaço, traço ou letra minúscula (`sac 2026-1116-0008`).

> Chamados abertos antes de 22/11/2026 mantêm o número antigo (que começa com **CH** ou **OC**) e continuam aparecendo na busca por esse número.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Como pedir ajuda e abrir um chamado](artigo:pedir-ajuda), [Registrar ocorrências](artigo:ocorrencias) e [Vistorias nos condomínios](artigo:vistorias).
