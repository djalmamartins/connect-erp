---
codigo: AP-128
titulo: Empenhos
resumo: Despesas reservadas antes do pagamento, para o dinheiro não ser usado em outra coisa. Na hora de pagar, liquide o empenho com a data e, se houver, o lançamento.
categoria: documentos
rota: /documentos/empenhos
menu: Documentos > Empenhos
icone: list-outline
situacao: funciona
relacionados: copias-de-cheques, malotes, avisos-de-recebimento, carta-de-anuencia
palavras: empenhos, documentos, AP-128
rascunho: nao
---

Despesas reservadas antes do pagamento, para o dinheiro não ser usado em outra coisa. Na hora de pagar, liquide o empenho com a data e, se houver, o lançamento.

## Onde fica

No menu lateral, abra [[Documentos > Empenhos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela empenhos
rota: /documentos/empenhos
condominio: residencial-jardim-das-acacias
legenda: Tela Empenhos
1: menu | Empenhos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo empenho") | Botão {{Novo empenho}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Novo empenho}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Descrição**, **Valor**, **Empenhado em** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela empenhos-novo-empenho
rota: /documentos/empenhos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo empenho
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
3: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
4: main form:not([method=get]) [name="committed_on"] | Empenhado em (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Fornecedor**.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Empenhado em** (obrigatório).
- **Liquidado em**.
- **Lançamento**.
- **Observações**.
- **Situação** (obrigatório): Opções: Empenhado, Liquidado e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Empenhos**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cópias de cheques](artigo:copias-de-cheques), [Malotes](artigo:malotes) e [Avisos de recebimento (AR)](artigo:avisos-de-recebimento).
