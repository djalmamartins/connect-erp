---
codigo: CN-004
titulo: Abrir e acompanhar chamados
resumo: Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do Help Desk, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.
categoria: operacional
rota: /operacional/chamados
menu: Operacional > Rotina > Chamados
icone: list-outline
situacao: funciona
relacionados: pedir-ajuda, ocorrencias, vistorias, manutencoes, chamados-do-whatsapp
palavras: chamados, operacional, CN-004, help desk, protocolo, suporte, atendimento
rascunho: nao
---

Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do Help Desk, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Chamados]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela chamados
rota: /operacional/chamados
condominio: residencial-jardim-das-acacias
legenda: Tela Chamados
1: menu | Chamados no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Abrir chamado") | Botão {{Abrir chamado}}
3: main .erp-page-head__actions :is(a,button):has-text("Fila do Help Desk") | Botão {{Fila do Help Desk}}
4: main :is(form[method=get], .erp-filters) | Filtros: Buscar, Condomínio e Situação
5: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Fila do Help Desk}}: abre a fila de atendimento no Help Desk (para quem atende os chamados).
- {{Abrir chamado}}: leva ao formulário de abertura, no alto da página.

## Abrir chamado

O chamado cai na fila do Help Desk e a equipe é avisada na hora.

1. Preencha **Assunto** e **Descrição**.
2. Clique em {{Enviar para o Help Desk}}.

> Enquanto você digita o assunto, a tela sugere artigos desta Central de Ajuda que falam do mesmo tema. Se um deles resolver, não precisa abrir o chamado; se não, siga normalmente.

```tela chamados-form
rota: /operacional/chamados
condominio: residencial-jardim-das-acacias
legenda: Abrir chamado
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio
2: main form:not([method=get]) [name="unit_id"] | Unidade
3: main form:not([method=get]) [name="subject"] | Assunto (obrigatório)
4: main form:not([method=get]) [name="message"] | Descrição (obrigatório)
5: main form:not([method=get]) [name="area"] | Área
6: main form:not([method=get]) button:has-text("Enviar para o Help Desk") | Botão {{Enviar para o Help Desk}}
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

Clique no protocolo na lista para abrir o chamado. Ali aparecem a conversa com a equipe e a situação atual. Para responder, escreva no campo de resposta e envie: se o chamado estava aguardando você, ele volta para a fila. Quando a equipe responde, o sino da barra superior avisa e leva direto ao chamado.

## Pesquisar e filtrar

Toda ocorrência também é um chamado. No filtro de tipo, **Só ocorrências** mostra apenas elas, e **Sem as ocorrências** mostra os outros chamados. O selo "Ocorrência #" na lista leva à ocorrência.

No alto da tela ficam os contadores da fila, como no Help Desk: um por situação, **SLA vencido**, **Vence hoje** e **Sem responsável**. Clique em um deles para filtrar.

Use os filtros no alto da lista: **Buscar**, **Condomínio**, **Situação**, **Prioridade**, **Responsável**, **Prazo**, **Origem** e **Tipo**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

As conversas do WhatsApp atendidas no Connect Talk também viram chamado, com a origem **WhatsApp (Connect Talk)**: veja [Chamados que chegam pelo WhatsApp](artigo:chamados-do-whatsapp).

## O que a lista mostra

Colunas: **Protocolo**, **Atualizado**, **Solicitante** (com condomínio, unidade e origem), **Assunto**, **Situação**, **Responsável**, **Prioridade** e **SLA** (o prazo, ou "Vencido" em vermelho).

## Atender pelo ERP

Quem faz parte da equipe de atendimento usa no ERP os mesmos recursos do Help Desk:

- **Cronômetro**: ao abrir o chamado, ele começa a contar o tempo dedicado. Dá para pausar ou zerar. O tempo é somado ao chamado quando você envia a mensagem.
- **Responder** ou **Nota interna**: a nota interna só a equipe vê.
- **Resposta rápida**, **Anexos** e **Resolver após enviar**.
- **Triagem**: situação, prioridade, responsável, categoria, equipe e tags, e o botão **Resolver chamado** (ou **Reabrir chamado**).
- **Detalhes**: primeira resposta, prazo SLA, tempo dedicado e número de respostas.
- **Histórico de alterações**: quem mudou o quê e quando.
- Na lista, marque vários chamados e escolha a situação em **Com os selecionados**.

> Se o pedido é de um morador, marque **Um morador ou outra pessoa** e informe nome, e-mail e telefone dele: a resposta vai para ele.

> Guarde o protocolo: ele identifica o chamado em qualquer conversa.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Como pedir ajuda e abrir um chamado](artigo:pedir-ajuda), [Registrar ocorrências](artigo:ocorrencias) e [Vistorias nos condomínios](artigo:vistorias).
