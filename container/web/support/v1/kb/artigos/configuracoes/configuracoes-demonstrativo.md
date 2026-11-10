---
codigo: AP-158
titulo: Modelo do demonstrativo
resumo: Define como o demonstrativo sai: modelo, agrupamento e se mostra o saldo anterior e a previsão orçamentária.
categoria: configuracoes
rota: /configuracoes/demonstrativo
menu: Configurações > Financeiro e parâmetros > Demonstrativo
icone: options-outline
situacao: exemplo
relacionados: financeiro, plano-de-contas, saldo-inicial-plano-de-contas, saldo-inicial-centro-de-custo
palavras: demonstrativo, configurações, AP-158
rascunho: nao
---

Define como o demonstrativo sai: modelo, agrupamento e se mostra o saldo anterior e a previsão orçamentária.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Demonstrativo]].

```tela configuracoes-demonstrativo
rota: /configuracoes/demonstrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo
1: menu | Demonstrativo no menu lateral
2: main form:not([method=get]) | Demonstrativo
```

## Demonstrativo

1. Preencha os campos que quer alterar.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela configuracoes-demonstrativo-form
rota: /configuracoes/demonstrativo
condominio: residencial-jardim-das-acacias
legenda: Demonstrativo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-158-0-modelo"] | Modelo
2: main form:not([method=get]) [name="ap-158-0-agrupar-por"] | Agrupar por
3: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Modelo**: Opções: Sintético e Analítico.
- **Agrupar por**: Opções: Plano de contas e Centro de custo.
- **Mostrar saldo anterior**.
- **Mostrar previsão orçamentária**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Configurações financeiras](artigo:financeiro), [Plano de contas](artigo:plano-de-contas) e [Saldo inicial do plano de contas](artigo:saldo-inicial-plano-de-contas).
