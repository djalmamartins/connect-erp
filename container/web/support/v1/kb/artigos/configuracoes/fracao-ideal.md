---
codigo: AP-167
titulo: Preencher a fração ideal e a área das unidades
resumo: A grade mostra todas as unidades do condomínio com a área privativa e a fração ideal, para preencher tudo de uma vez. A fração ideal define a parte de cada unidade no rateio das despesas.
categoria: configuracoes
rota: /configuracoes/fracao-ideal
menu: Configurações > Financeiro e parâmetros > Fração Ideal
icone: options-outline
situacao: funciona
relacionados: parametros, tipos-de-faturas, indices, imposto
palavras: fração ideal, configurações, AP-167, rateio, área privativa
rascunho: nao
---

A grade mostra todas as unidades do condomínio com a área privativa e a fração ideal, para preencher tudo de uma vez. A fração ideal define a parte de cada unidade no rateio das despesas.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Fração Ideal]].

```tela fracao-ideal
rota: /configuracoes/fracao-ideal
condominio: residencial-jardim-das-acacias
legenda: Tela Fração Ideal
1: menu | Fração Ideal no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Cadastro rápido") | Botão {{Cadastro rápido}}
3: main .erp-page-head__actions :is(a,button):has-text("Unidades") | Botão {{Unidades}}
4: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Unidades}}: volta para a lista de unidades.
- {{Cadastro rápido}}: gera as unidades em sequência, se ainda faltarem.

## Unidades de Residencial Jardim das Acácias

Fração em decimal (0,012500) ou em porcentagem (1,25%). Enter vai para a linha de baixo.

```tela fracao-ideal-form
rota: /configuracoes/fracao-ideal
condominio: residencial-jardim-das-acacias
legenda: Unidades de Residencial Jardim das Acácias
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="private_area"] | Área de A - 101
2: main form:not([method=get]) [name="ideal_fraction"] | Fração ideal de A - 101
3: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

## O que a lista mostra

Colunas: **Unidade**, **Andar**, **Tipo**, **Área privativa (m²)**, **Fração ideal** e **%**.

## Dividir igualmente ou pela área

1. Preencha a **área privativa** de cada unidade, se for dividir pela área.
2. Use **Dividir igualmente** ou **Dividir pela área**: a grade se preenche, sem gravar ainda.
3. Ajuste o que for diferente. A fração pode ser decimal (0,0125) ou porcentagem (1,25%).
4. Clique em {{Salvar}}. A soma das frações precisa dar 1 (100%).

>! O sistema só grava se a soma das frações der exatamente 1. Se faltar ou sobrar, a tela mostra a diferença.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Parâmetros gerais](artigo:parametros), [Tipos de faturas](artigo:tipos-de-faturas) e [Índices e regra de cobrança](artigo:indices).
