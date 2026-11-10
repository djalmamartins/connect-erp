---
codigo: AP-165
titulo: Índices e regra de cobrança
resumo: A regra de cobrança de cada condomínio (multa, juros e índice de correção das contas em atraso, e quando um acordo é considerado quebrado) e os índices mensais usados na correção.
categoria: configuracoes
rota: /configuracoes/indices
menu: Configurações > Financeiro e parâmetros > Índices
icone: list-outline
situacao: funciona
relacionados: relatorio-memoria-de-calculo, imposto, parametros, previsao-orcamentaria
palavras: índices, configurações, AP-165
rascunho: nao
---

A regra de cobrança de cada condomínio (multa, juros e índice de correção das contas em atraso, e quando um acordo é considerado quebrado) e os índices mensais usados na correção.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Índices]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela indices
rota: /configuracoes/indices
condominio: residencial-jardim-das-acacias
legenda: Tela Índices
1: menu | Índices no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Competência, Variação (%) e Importar arquivo (linhas AAAA-MM;variação)
3: main form:not([method=get]) | Formulário
```

## Como usar

Hoje: multa de 2,00%, juros de 1,00% ao mês e correção pelo IPCA. A quebra do acordo nunca é automática: o ERP só indica e alguém marca.

1. Preencha **Multa (%)**, **Juros (% ao mês)**, **Quebra: parcela atrasada há mais de (dias)** e **ou parcelas vencidas em aberto**.
2. Clique em {{Salvar regra}}.

```tela indices-form
rota: /configuracoes/indices
condominio: residencial-jardim-das-acacias
legenda: Como usar
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="fine_pct"] | Multa (%) (obrigatório)
2: main form:not([method=get]) [name="interest_pct"] | Juros (% ao mês) (obrigatório)
3: main form:not([method=get]) [name="price_index"] | Correção
4: main form:not([method=get]) [name="breach_days_late"] | Quebra: parcela atrasada há mais de (dias) (obrigatório)
5: main form:not([method=get]) [name="breach_open_installments"] | ou parcelas vencidas em aberto (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar regra") | Botão {{Salvar regra}}
```

### O que preencher

- **Multa (%)** (obrigatório): Uma vez, sobre o valor corrigido (até 10%).
- **Juros (% ao mês)** (obrigatório): Pro rata por dia de atraso.
- **Correção**: Opções: IPCA e Sem correção.
- **Quebra: parcela atrasada há mais de (dias)** (obrigatório).
- **ou parcelas vencidas em aberto** (obrigatório): 0 = não usar este critério.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Competência**, **Variação (%)** e **Importar arquivo (linhas AAAA-MM;variação)**.
Depois clique em {{Lançar}}. Para voltar à lista completa, limpe os filtros.

## Lançar o índice do mês

1. Informe a **Competência** e a **Variação (%)** do índice.
2. Ou importe um arquivo com uma linha por mês, no formato `AAAA-MM;variação`.
3. Os encargos passam a usar o índice na hora.

> Na tela: *Sem índice, a correção fica em zero. Lance os meses ou importe um arquivo.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Memória de cálculo dos encargos](artigo:relatorio-memoria-de-calculo), [Impostos retidos](artigo:imposto) e [Parâmetros gerais](artigo:parametros).
