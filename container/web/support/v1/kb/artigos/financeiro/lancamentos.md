---
codigo: AP-020
titulo: Cadastrar e gerenciar lançamentos
resumo: Receitas e despesas de cada condomínio, com conta, centro de custo, vencimento e pagamento.
categoria: financeiro
rota: /financeiro/lancamentos
menu: Financeiro > Lançamentos > Lançamentos
icone: list-outline
situacao: funciona
palavras: lançamentos, financeiro, AP-020
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos]].

```tela lancamentos
rota: /financeiro/lancamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos
1: menu | Lançamentos no menu lateral
2: main :is(a,button):has-text("Novo lançamento") | Botão {{Novo lançamento}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Natureza, Situação
4: main table thead | Lista com Descrição, Condomínio, Natureza, Competência, Valor, Vencimento, Pago em e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo lançamento}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela lancamentos-novo
rota: /financeiro/lancamentos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de lançamento
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Natureza** (obrigatório): opções: Receita e Despesa.
- **Competência** (obrigatório): aAAA-MM.
- **Descrição** (obrigatório).
- **Valor** (obrigatório): em reais, por exemplo 1.250,00.
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Vencimento** (obrigatório): no formato dia/mês/ano.
- **Pago em**: vazio enquanto estiver em aberto. No formato dia/mês/ano.
- **Conta bancária**: obrigatória quando o lançamento está pago.
- **Fornecedor**.
- **Unidade**: unidade que deve a receita (faturas preenchem sozinhas). Escolha entre as unidades do condomínio selecionado.
- **Nº do documento**: nota fiscal, recibo ou boleto.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

