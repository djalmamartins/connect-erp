---
codigo: AP-028
titulo: Cobranças extras (rateio ou valor fixo)
resumo: Cobrança fora da fatura do mês para uma ou mais unidades, como chamada de capital ou uma obra aprovada em assembleia. O valor pode ser fixo por unidade, o total dividido igualmente ou rateado pela fração ideal. Gera uma conta a receber por unidade.
categoria: financeiro
rota: /financeiro/cobrancas-extras
menu: Financeiro > Lançamentos > Cobranças Extras
icone: list-outline
situacao: funciona
relacionados: lancar-multa-avulsa, outros-lancamentos, lancar-desconto, lancar-receita
palavras: cobranças extras, financeiro, AP-028
rascunho: nao
---

Cobrança fora da fatura do mês para uma ou mais unidades, como chamada de capital ou uma obra aprovada em assembleia. O valor pode ser fixo por unidade, o total dividido igualmente ou rateado pela fração ideal. Gera uma conta a receber por unidade.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Cobranças Extras]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela cobrancas-extras
rota: /financeiro/cobrancas-extras
condominio: residencial-jardim-das-acacias
legenda: Tela Cobranças Extras
1: menu | Cobranças Extras no menu lateral
2: main form:not([method=get]) | Lançar cobrança extra
```

## Lançar cobrança extra

A conta nasce em aberto, no vencimento escolhido, e entra na cobrança da unidade.

```tela cobrancas-extras-form
rota: /financeiro/cobrancas-extras
condominio: residencial-jardim-das-acacias
legenda: Lançar cobrança extra
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
2: main form:not([method=get]) [name="chart_account_id"] | Conta de receita (obrigatório)
3: main form:not([method=get]) [name="competence"] | Competência (obrigatório)
4: main form:not([method=get]) [name="due_on"] | Vencimento (obrigatório)
5: main form:not([method=get]) [name="amount_cents"] | Valor por unidade (R$) (obrigatório)
6: main form:not([method=get]) button:has-text("Lançar cobrança") | Botão {{Lançar cobrança}}
```

### O que preencher

- **Descrição** (obrigatório): Exemplo: Ex.: Fundo de obras da fachada.
- **Conta de receita** (obrigatório).
- **Competência** (obrigatório).
- **Vencimento** (obrigatório).
- **Cálculo**: Opções: Valor fixo por unidade, Total dividido igualmente e Total rateado pela fração ideal.
- **Valor por unidade (R$)** (obrigatório).
- **Todas as unidades**.

> Rateio pela fração ideal só funciona com as frações preenchidas e somando 100%.

> Na tela: *Use o formulário acima para lançar a primeira.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar uma multa avulsa](artigo:lancar-multa-avulsa), [Transferir entre contas bancárias](artigo:outros-lancamentos) e [Lançar um desconto numa conta a receber](artigo:lancar-desconto).
