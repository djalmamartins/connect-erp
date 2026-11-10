---
codigo: AP-027
titulo: Lançar uma multa avulsa
resumo: Multa de uma unidade por descumprir o regulamento ou por uma ocorrência, com valor fixo e motivo. A multa vira uma conta a receber da unidade e entra na cobrança normal.
categoria: financeiro
rota: /financeiro/lancar-multa-avulsa
menu: Financeiro > Lançamentos > Lançar Multa (Avulsa)
icone: create-outline
situacao: funciona
relacionados: multas-pendentes, ocorrencias, lancar-desconto, cobrancas-extras
palavras: lançar multa (avulsa), financeiro, AP-027
rascunho: nao
---

Multa de uma unidade por descumprir o regulamento ou por uma ocorrência, com valor fixo e motivo. A multa vira uma conta a receber da unidade e entra na cobrança normal.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Multa (Avulsa)]].

```tela lancar-multa-avulsa
rota: /financeiro/lancar-multa-avulsa
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Multa (Avulsa)
1: menu | Lançar Multa (Avulsa) no menu lateral
2: main form:not([method=get]) | Lançar multa
```

## Lançar multa

A conta nasce em aberto, no vencimento escolhido, e entra na cobrança da unidade.

1. Preencha **Descrição**, **Conta de receita**, **Competência**, **Vencimento**, **Valor (R$)**, **Unidade** e **Motivo**.
2. Clique em {{Lançar multa}}.

```tela lancar-multa-avulsa-form
rota: /financeiro/lancar-multa-avulsa
condominio: residencial-jardim-das-acacias
legenda: Lançar multa
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
2: main form:not([method=get]) [name="chart_account_id"] | Conta de receita (obrigatório)
3: main form:not([method=get]) :is(.erp-month:has([name="competence"]), [name="competence"]:not([type=hidden])) | Competência (obrigatório)
4: main form:not([method=get]) [name="due_on"] | Vencimento (obrigatório)
5: main form:not([method=get]) [name="amount_cents"] | Valor (R$) (obrigatório)
6: main form:not([method=get]) button:has-text("Lançar multa") | Botão {{Lançar multa}}
```

### O que preencher

- **Descrição** (obrigatório): Exemplo: Ex.: Multa por barulho após 22h.
- **Conta de receita** (obrigatório).
- **Competência** (obrigatório).
- **Vencimento** (obrigatório).
- **Valor (R$)** (obrigatório).
- **Unidade** (obrigatório).
- **Origem (opcional)**: Ocorrências do condomínio ou da unidade escolhida e reservas da unidade. Opções: Sem origem registrada, nº 2 · 08/11/2026 · Lâmpada queimada na garagem, nº 1 · 06/11/2026 · Barulho após as 22h, nº 1 · 21/11/2026 · Salão de festas e nº 2 · 14/11/2026 · Churrasqueira.
- **Motivo** (obrigatório): Exemplo: Ex.: art. 12 do regulamento interno, ocorrência nº 45.

> Na tela: *Use o formulário acima para lançar a primeira.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Multas pendentes](artigo:multas-pendentes), [Registrar ocorrências](artigo:ocorrencias) e [Lançar um desconto numa conta a receber](artigo:lancar-desconto).
