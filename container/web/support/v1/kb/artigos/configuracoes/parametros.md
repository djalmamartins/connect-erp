---
codigo: AP-166
titulo: Parâmetros gerais
resumo: Fuso horário, moeda, arredondamento e o tempo para a sessão expirar.
categoria: configuracoes
rota: /configuracoes/parametros
menu: Configurações > Financeiro e parâmetros > Parâmetros
icone: options-outline
situacao: exemplo
relacionados: indices, fracao-ideal, imposto, tipos-de-faturas
palavras: parâmetros, configurações, AP-166
rascunho: nao
---

Fuso horário, moeda, arredondamento e o tempo para a sessão expirar.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Parâmetros]].

```tela parametros
rota: /configuracoes/parametros
condominio: residencial-jardim-das-acacias
legenda: Tela Parâmetros
1: menu | Parâmetros no menu lateral
2: main form:not([method=get]) | Parâmetros gerais
```

## Parâmetros gerais

1. Preencha os campos que quer alterar.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela parametros-form
rota: /configuracoes/parametros
condominio: residencial-jardim-das-acacias
legenda: Parâmetros gerais
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-166-0-fuso-horario"] | Fuso horário
2: main form:not([method=get]) [name="ap-166-0-moeda"] | Moeda
3: main form:not([method=get]) [name="ap-166-0-arredondamento"] | Arredondamento
4: main form:not([method=get]) [name="ap-166-0-dias-para-expirar-sessao"] | Dias para expirar sessão
5: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Fuso horário**: Opções: America/Sao_Paulo.
- **Moeda**: Opções: Real (R$).
- **Arredondamento**: Opções: 2 casas e Sem centavos.
- **Dias para expirar sessão**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Índices e regra de cobrança](artigo:indices), [Preencher a fração ideal e a área das unidades](artigo:fracao-ideal) e [Impostos retidos](artigo:imposto).
