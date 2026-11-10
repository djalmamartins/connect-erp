---
codigo: AP-161
titulo: Saldo inicial do plano de contas
resumo: O saldo de cada conta do plano na data em que o condomínio começou a ser administrado pela Connect. É o ponto de partida do razão e do balanço.
categoria: configuracoes
rota: /configuracoes/saldo-inicial-plano-de-contas
menu: Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)
icone: options-outline
situacao: exemplo
relacionados: plano-de-contas, saldo-inicial-centro-de-custo, configuracoes-demonstrativo, previsao-orcamentaria
palavras: saldo inicial (plano de contas), configurações, AP-161
rascunho: nao
---

O saldo de cada conta do plano na data em que o condomínio começou a ser administrado pela Connect. É o ponto de partida do razão e do balanço.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)]].

```tela saldo-inicial-plano-de-contas
rota: /configuracoes/saldo-inicial-plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Saldo inicial (plano de contas)
1: menu | Saldo inicial (plano de contas) no menu lateral
2: main form:not([method=get]) | Saldo inicial por conta
```

## Saldo inicial por conta

1. Preencha **Condomínio**, **Data de referência**, **Conta do plano** e **Saldo inicial**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela saldo-inicial-plano-de-contas-form
rota: /configuracoes/saldo-inicial-plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Saldo inicial por conta
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-161-0-condominio"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="ap-161-0-data-de-referencia"] | Data de referência (obrigatório)
3: main form:not([method=get]) [name="ap-161-0-conta-do-plano"] | Conta do plano (obrigatório)
4: main form:not([method=get]) [name="ap-161-0-saldo-inicial"] | Saldo inicial (obrigatório)
5: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Data de referência** (obrigatório).
- **Conta do plano** (obrigatório).
- **Saldo inicial** (obrigatório): Em reais, ex.: 1.250,00.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Plano de contas](artigo:plano-de-contas), [Saldo inicial dos centros de custo](artigo:saldo-inicial-centro-de-custo) e [Modelo do demonstrativo](artigo:configuracoes-demonstrativo).
