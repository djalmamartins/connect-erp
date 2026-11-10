---
codigo: AP-157
titulo: Configurações financeiras
resumo: Padrões da cobrança: multa por atraso, juros ao mês, dias de carência, correção, desconto por pontualidade, conta bancária padrão e carteira.
categoria: configuracoes
rota: /configuracoes/financeiro
menu: Configurações > Financeiro e parâmetros > Financeiro
icone: options-outline
situacao: exemplo
relacionados: indices, configuracoes-demonstrativo, plano-de-contas, saldo-inicial-plano-de-contas
palavras: financeiro, configurações, AP-157
rascunho: nao
---

Padrões da cobrança: multa por atraso, juros ao mês, dias de carência, correção, desconto por pontualidade, conta bancária padrão e carteira.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Financeiro]].

```tela financeiro
rota: /configuracoes/financeiro
condominio: residencial-jardim-das-acacias
legenda: Tela Financeiro
1: menu | Financeiro no menu lateral
2: main form:not([method=get]) | Cobrança
```

## Cobrança

1. Preencha **Multa por atraso (%)** e **Juros ao mês (%)**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela financeiro-form
rota: /configuracoes/financeiro
condominio: residencial-jardim-das-acacias
legenda: Cobrança
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-157-0-multa-por-atraso"] | Multa por atraso (%) (obrigatório)
2: main form:not([method=get]) [name="ap-157-0-juros-ao-mes"] | Juros ao mês (%) (obrigatório)
3: main form:not([method=get]) [name="ap-157-0-dias-de-carencia"] | Dias de carência
4: main form:not([method=get]) [name="ap-157-0-correcao"] | Correção
5: main form:not([method=get]) [name="ap-157-0-desconto-por-pontualidade"] | Desconto por pontualidade (%)
6: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Multa por atraso (%)** (obrigatório).
- **Juros ao mês (%)** (obrigatório).
- **Dias de carência**.
- **Correção**: Opções: IPCA, IGP-M e Sem correção.
- **Desconto por pontualidade (%)**.
- **Conta bancária padrão**.
- **Carteira de cobrança**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Índices e regra de cobrança](artigo:indices), [Modelo do demonstrativo](artigo:configuracoes-demonstrativo) e [Plano de contas](artigo:plano-de-contas).
