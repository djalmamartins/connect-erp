---
codigo: AP-031
titulo: Pagamentos pelo banco
resumo: Pagamentos feitos pelas contas bancárias do condomínio, com favorecido, data e valor.
categoria: financeiro
rota: /financeiro/pagamentos-bancos
menu: Financeiro > Contas e pagamentos > Pagamentos (Bancos)
icone: list-outline
situacao: exemplo
relacionados: faturas, recibos, contas-a-receber, contas-a-pagar
palavras: pagamentos (bancos), financeiro, AP-031
rascunho: nao
---

Pagamentos feitos pelas contas bancárias do condomínio, com favorecido, data e valor.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Pagamentos (Bancos)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela pagamentos-bancos
rota: /financeiro/pagamentos-bancos
condominio: residencial-jardim-das-acacias
legenda: Tela Pagamentos (Bancos)
1: menu | Pagamentos (Bancos) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo pagamento") | Botão {{Novo pagamento}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até e Situação
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo pagamento}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Conta bancária**, **Favorecido**, **Data** e **Valor**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela pagamentos-bancos-novo-pagamento
rota: /financeiro/pagamentos-bancos
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo pagamento
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Novo pagamento")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-031-condominio"] | Condomínio (obrigatório)
2: dialog[open] [name="ap-031-conta-bancaria"] | Conta bancária (obrigatório)
3: dialog[open] [name="ap-031-favorecido"] | Favorecido (obrigatório)
4: dialog[open] [name="ap-031-data"] | Data (obrigatório)
5: dialog[open] [name="ap-031-valor"] | Valor (obrigatório)
6: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Conta bancária** (obrigatório).
- **Favorecido** (obrigatório).
- **Data** (obrigatório).
- **Valor** (obrigatório): Em reais, ex.: 1.250,00.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio**, **De**, **Até** e **Situação**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Data**, **Conta bancária**, **Favorecido**, **Condomínio**, **Valor** e **Situação**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Faturas](artigo:faturas), [Emitir recibos de pagamento](artigo:recibos) e [Contas a receber](artigo:contas-a-receber).
