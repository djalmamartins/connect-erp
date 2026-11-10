---
codigo: CN-002
titulo: Cadastrar centros de custo
resumo: Centros de custo agrupam lançamentos por área ou projeto, como obra da fachada ou manutenção predial, para saber quanto cada um consumiu. Sem condomínio, o centro de custo vale para todos.
categoria: financeiro
rota: /financeiro/centros-de-custo
menu: Financeiro > Cadastros financeiros > Centros de custo
icone: list-outline
situacao: funciona
relacionados: relatorio-de-centros-de-custo, saldo-inicial-centro-de-custo, contas-bancarias, recibos
palavras: centros de custo, financeiro, CN-002
rascunho: nao
---

Centros de custo agrupam lançamentos por área ou projeto, como obra da fachada ou manutenção predial, para saber quanto cada um consumiu. Sem condomínio, o centro de custo vale para todos.

## Onde fica

No menu lateral, abra [[Financeiro > Cadastros financeiros > Centros de custo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela centros-de-custo
rota: /financeiro/centros-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Centros de custo
1: menu | Centros de custo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo centro de custo") | Botão {{Novo centro de custo}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo centro de custo}}, no alto da lista.
2. Preencha os campos obrigatórios: **Código**, **Nome** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela centros-de-custo-novo-centro-de-custo
rota: /financeiro/centros-de-custo/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo centro de custo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio
2: main form:not([method=get]) [name="code"] | Código (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome (obrigatório)
4: main form:not([method=get]) [name="notes"] | Observações
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: centro de custo padrão da Connect (todos os condomínios).
- **Código** (obrigatório).
- **Nome** (obrigatório): Ex.: Portaria, Limpeza, Obra do bloco B.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Código**, **Condomínio**, **Nome** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de centros de custo](artigo:relatorio-de-centros-de-custo), [Saldo inicial dos centros de custo](artigo:saldo-inicial-centro-de-custo) e [Cadastrar contas bancárias](artigo:contas-bancarias).
