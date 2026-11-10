---
codigo: AP-163
titulo: Previsão orçamentária
resumo: O orçamento do exercício: reajuste previsto e fundo de reserva. Ele é comparado com o realizado no relatório de previsão orçamentária.
categoria: configuracoes
rota: /configuracoes/previsao-orcamentaria
menu: Configurações > Financeiro e parâmetros > Previsão Orçamentária
icone: options-outline
situacao: exemplo
relacionados: saldo-inicial-centro-de-custo, imposto, saldo-inicial-plano-de-contas, indices
palavras: previsão orçamentária, configurações, AP-163
rascunho: nao
---

O orçamento do exercício: reajuste previsto e fundo de reserva. Ele é comparado com o realizado no relatório de previsão orçamentária.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Previsão Orçamentária]].

```tela previsao-orcamentaria
rota: /configuracoes/previsao-orcamentaria
condominio: residencial-jardim-das-acacias
legenda: Tela Previsão Orçamentária
1: menu | Previsão Orçamentária no menu lateral
2: main form:not([method=get]) | Previsão orçamentária
```

## Previsão orçamentária

1. Preencha **Condomínio** e **Exercício**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela previsao-orcamentaria-form
rota: /configuracoes/previsao-orcamentaria
condominio: residencial-jardim-das-acacias
legenda: Previsão orçamentária
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-163-0-condominio"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="ap-163-0-exercicio"] | Exercício (obrigatório)
3: main form:not([method=get]) [name="ap-163-0-reajuste-previsto"] | Reajuste previsto (%)
4: main form:not([method=get]) [name="ap-163-0-fundo-de-reserva"] | Fundo de reserva (%)
5: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Exercício** (obrigatório): Opções: 2026 e 2027.
- **Reajuste previsto (%)**.
- **Fundo de reserva (%)**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Saldo inicial dos centros de custo](artigo:saldo-inicial-centro-de-custo), [Impostos retidos](artigo:imposto) e [Saldo inicial do plano de contas](artigo:saldo-inicial-plano-de-contas).
