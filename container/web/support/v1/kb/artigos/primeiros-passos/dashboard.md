---
codigo: AP-001
titulo: Dashboard: o resumo do dia
resumo: O Dashboard é a primeira tela depois de entrar. Ele mostra, de um condomínio ou da carteira inteira, o que está para vencer, o que venceu, as ocorrências abertas, os alertas do dia e a agenda. É daqui que você decide o que fazer primeiro.
categoria: primeiros-passos
rota: /dashboard
menu: Dashboard
icone: speedometer-outline
situacao: exemplo
palavras: dashboard, AP-001, início, painel, resumo, alertas, agenda
rascunho: nao
---

O Dashboard é a primeira tela depois de entrar. Ele mostra, de um condomínio ou da carteira inteira, o que está para vencer, o que venceu, as ocorrências abertas, os alertas do dia e a agenda. É daqui que você decide o que fazer primeiro.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Dashboard]].

```tela dashboard
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Tela Dashboard
1: menu | Dashboard no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Comunicado rápido") | Botão {{Comunicado rápido}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main table thead | Colunas da lista
```

## Comunicado rápido

1. Clique em {{Comunicado rápido}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Título** e **Mensagem**. Os outros podem ficar para depois.
3. Clique em {{Enviar comunicado}}.

```tela dashboard-comunicado-rapido
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Comunicado rápido
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Comunicado rápido")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] button:has-text("Enviar comunicado") | Botão {{Enviar comunicado}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Título** (obrigatório): Exemplo: Ex.: Manutenção da caixa d'água.
- **Mensagem** (obrigatório): Mínimo de 10 caracteres.

## O que a lista mostra

Colunas: **Mês**, **Receitas** e **Despesas**.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

> Escolha **Todos os condomínios** na barra superior para ver a carteira inteira; escolha um condomínio para ver só os números dele.

> Os cartões levam para a tela de origem: clique em **A receber vencido** para abrir as contas, por exemplo.

> Na tela: *Dados de demonstração Os valores desta tela são fictícios e servem para avaliar o layout. Nenhum dado é gravado. Para desligar, defina ERP_DEMO_DATA=false.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Dashboard**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).
