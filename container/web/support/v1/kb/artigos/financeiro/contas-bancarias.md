---
codigo: CN-003
titulo: Cadastrar contas bancárias
resumo: Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal. A conta de cobrança precisa dos dados do convênio para emitir boletos.
categoria: financeiro
rota: /financeiro/contas-bancarias
menu: Financeiro > Cadastros financeiros > Contas bancárias
icone: list-outline
situacao: funciona
relacionados: saldos-de-contas, extrato-conta, boletos, centros-de-custo
palavras: contas bancárias, financeiro, CN-003, banco, agência, conta corrente, convênio
rascunho: nao
---

Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal. A conta de cobrança precisa dos dados do convênio para emitir boletos.

## Onde fica

No menu lateral, abra [[Financeiro > Cadastros financeiros > Contas bancárias]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contas-bancarias
rota: /financeiro/contas-bancarias
condominio: residencial-jardim-das-acacias
legenda: Tela Contas bancárias
1: menu | Contas bancárias no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova conta bancária") | Botão {{Nova conta bancária}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova conta bancária}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Apelido**, **Banco (código)**, **Agência**, **Conta**, **Tipo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela contas-bancarias-nova-conta-bancaria
rota: /financeiro/contas-bancarias/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova conta bancária
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="name"] | Apelido (obrigatório)
3: main form:not([method=get]) [name="bank_code"] | Banco (código) (obrigatório)
4: main form:not([method=get]) [name="branch"] | Agência (obrigatório)
5: main form:not([method=get]) [name="account_number"] | Conta (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Apelido** (obrigatório): Ex.: Conta movimento.
- **Banco (código)** (obrigatório).
- **Agência** (obrigatório).
- **Conta** (obrigatório).
- **Tipo** (obrigatório): Opções: Conta corrente, Poupança e Aplicação.
- **Carteira de cobrança**: Quando houver.
- **Saldo inicial**.
- **Data do saldo inicial**.
- **Conta principal**.
- **Centro de custo padrão**: Opcional; sugerido nos lançamentos desta conta.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Apelido**, **Condomínio**, **Banco (código)**, **Agência**, **Conta**, **Conta principal** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

>! Os dados do convênio bancário só são usados em homologação. Boleto real depende da liberação do banco.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Saldos das contas bancárias](artigo:saldos-de-contas), [Extrato da conta bancária](artigo:extrato-conta) e [Boletos](artigo:boletos).
