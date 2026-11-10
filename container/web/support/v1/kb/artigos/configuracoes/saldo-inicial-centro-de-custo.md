---
codigo: AP-162
titulo: Saldo inicial dos centros de custo
resumo: O saldo de cada centro de custo na data de início, ponto de partida dos relatórios de centro de custo.
categoria: configuracoes
rota: /configuracoes/saldo-inicial-centro-de-custo
menu: Configurações > Financeiro e parâmetros > Saldo inicial (centro de custo)
icone: options-outline
situacao: exemplo
relacionados: saldo-inicial-plano-de-contas, previsao-orcamentaria, plano-de-contas, imposto
palavras: saldo inicial (centro de custo), configurações, AP-162
rascunho: nao
---

O saldo de cada centro de custo na data de início, ponto de partida dos relatórios de centro de custo.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Saldo inicial (centro de custo)]].

```tela saldo-inicial-centro-de-custo
rota: /configuracoes/saldo-inicial-centro-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Saldo inicial (centro de custo)
1: menu | Saldo inicial (centro de custo) no menu lateral
2: main form:not([method=get]) | Saldo inicial por centro de custo
```

## Saldo inicial por centro de custo

1. Preencha **Condomínio**, **Data de referência**, **Centro de custo** e **Saldo inicial**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela saldo-inicial-centro-de-custo-form
rota: /configuracoes/saldo-inicial-centro-de-custo
condominio: residencial-jardim-das-acacias
legenda: Saldo inicial por centro de custo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-162-0-condominio"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="ap-162-0-data-de-referencia"] | Data de referência (obrigatório)
3: main form:not([method=get]) [name="ap-162-0-centro-de-custo"] | Centro de custo (obrigatório)
4: main form:not([method=get]) [name="ap-162-0-saldo-inicial"] | Saldo inicial (obrigatório)
5: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Data de referência** (obrigatório).
- **Centro de custo** (obrigatório).
- **Saldo inicial** (obrigatório): Em reais, ex.: 1.250,00.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Saldo inicial do plano de contas](artigo:saldo-inicial-plano-de-contas), [Previsão orçamentária](artigo:previsao-orcamentaria) e [Plano de contas](artigo:plano-de-contas).
