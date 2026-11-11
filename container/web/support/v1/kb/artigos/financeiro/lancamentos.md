---
codigo: AP-020
titulo: Lançamentos: receitas e despesas
resumo: Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.
categoria: financeiro
rota: /financeiro/lancamentos
menu: Financeiro > Lançamentos > Lançamentos
icone: list-outline
situacao: funciona
relacionados: lancamentos-fixos, lancar-despesa, lancar-receita, lancar-desconto, livro-caixa
palavras: lançamentos, financeiro, AP-020, anexo, nota fiscal, comprovante, recibo, receita, despesa, baixa, pagamento, competência, mês, plano de contas, erro ao salvar
rascunho: nao
---

Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lancamentos
rota: /financeiro/lancamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos
1: menu | Lançamentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo lançamento") | Botão {{Novo lançamento}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Natureza e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo lançamento}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Natureza**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o alto do formulário lista os campos com problema e cada um fica marcado em vermelho com a explicação.

```tela lancamentos-novo-lancamento
rota: /financeiro/lancamentos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo lançamento
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="nature"] | Natureza (obrigatório)
3: main form:not([method=get]) :is(.erp-month:has([name="competence"]), [name="competence"]:not([type=hidden])) | Competência (obrigatório)
4: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
5: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Natureza** (obrigatório): Opções: Receita e Despesa.
- **Competência** (obrigatório): mês e ano de referência, ex.: nov/2026. Clique no calendário ao lado do campo e escolha o mês (use as setas para trocar o ano), ou digite 11/2026.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório): só aparecem as contas da natureza escolhida (receita ou despesa), por exemplo **2.04.01 Manutenção elétrica**. As contas do Balanço, como 8.1 Contas a pagar, não recebem lançamento.
- **Centro de custo**: opcional.
- **Vencimento** (obrigatório).
- **Pago em**: Vazio enquanto estiver em aberto.
- **Conta bancária**: só é obrigatória quando o lançamento está pago (com **Pago em** preenchido). Para lançar uma conta em aberto, deixe as duas em branco.
- **Fornecedor**.
- **Unidade**: Unidade que deve a receita (faturas preenchem sozinhas).
- **Nº do documento**: Nota fiscal, recibo ou boleto.
- **Observações**.
- **Situação** (obrigatório): Opções: Válido e Cancelado.

## Anexar nota fiscal e comprovante

Depois de salvar, o lançamento mostra o quadro **Notas e comprovantes**, ao lado do formulário.

1. Abra o lançamento (clique no nome dele na lista).
2. No quadro **Notas e comprovantes**, escolha o **Tipo**: Nota fiscal, Comprovante de pagamento, Recibo ou Outro documento.
3. Escolha o **Arquivo** (PDF, foto JPG/PNG/WebP ou o XML da NF-e, até 20 MB) e clique em {{Anexar}}.
4. O anexo aparece na lista do quadro. Clique no nome para abrir ou no ícone de download para baixar.

Os anexos entram numerados no [Livro caixa mensal](artigo:livro-caixa). O arquivo fica guardado também no Disco Virtual, na pasta **Notas fiscais** ou **Comprovantes**. Se a mesma nota servir a duas parcelas, anexe o mesmo arquivo nas duas: o sistema guarda uma cópia só.

>! Dá para anexar mesmo com o mês fechado (o comprovante costuma chegar depois). Remover um anexo de mês fechado não: reabra o mês antes.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Natureza** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Natureza**, **Competência**, **Valor**, **Vencimento**, **Pago em** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Para dar baixa, edite o lançamento e informe **Pago em** e a **Conta bancária**.

> Use a competência (mês de referência) certa: é ela que decide em que mês o valor aparece nos relatórios.

>! Se a lista de contas aparecer vazia, cadastre as contas de receita e despesa em [[Configurações > Financeiro e parâmetros > Plano de Contas]]. O plano padrão da Connect já traz as mais comuns.

>! Mês fechado no Fechamento Mensal não aceita lançamento novo nem alteração até ser reaberto.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançamentos fixos (recorrentes)](artigo:lancamentos-fixos), [Lançar uma despesa](artigo:lancar-despesa) e [Lançar uma receita](artigo:lancar-receita).
