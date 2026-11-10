---
codigo: AP-043
titulo: Tarifas de consumo
resumo: Faixas progressivas de tarifa por tipo de medidor e vigência, usadas para calcular o valor das leituras. Sem condomínio vale o padrão da Connect; a tarifa do condomínio substitui o padrão.
categoria: operacional
rota: /operacional/tarifas
menu: Operacional > Leituras e consumo > Tarifas
icone: list-outline
situacao: funciona
relacionados: leituras, relogios, gerenciamento, previsao-de-visita
palavras: tarifas, operacional, AP-043
rascunho: nao
---

Faixas progressivas de tarifa por tipo de medidor e vigência, usadas para calcular o valor das leituras. Sem condomínio vale o padrão da Connect; a tarifa do condomínio substitui o padrão.

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Tarifas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela tarifas
rota: /operacional/tarifas
condominio: residencial-jardim-das-acacias
legenda: Tela Tarifas
1: menu | Tarifas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova faixa de tarifa") | Botão {{Nova faixa de tarifa}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
```

## Como cadastrar

1. Clique em {{Nova faixa de tarifa}}, no alto da lista.
2. Preencha os campos obrigatórios: **Tipo**, **Faixa**, **De (m³ ou kWh)**, **Valor por m³ (ou kWh)**, **Vigência a partir de** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela tarifas-nova-faixa-de-tarifa
rota: /operacional/tarifas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova faixa de tarifa
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
2: main form:not([method=get]) [name="name"] | Faixa (obrigatório)
3: main form:not([method=get]) [name="from_amount"] | De (m³ ou kWh) (obrigatório)
4: main form:not([method=get]) [name="price_cents"] | Valor por m³ (ou kWh) (obrigatório)
5: main form:not([method=get]) [name="valid_from"] | Vigência a partir de (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: tarifa padrão da Connect.
- **Tipo** (obrigatório): Opções: Água, Gás e Energia.
- **Faixa** (obrigatório): Ex.: Faixa 1.
- **De (m³ ou kWh)** (obrigatório).
- **Até**: Vazio = sem limite.
- **Valor por m³ (ou kWh)** (obrigatório).
- **Vigência a partir de** (obrigatório).
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Tarifas**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar as leituras do mês](artigo:leituras), [Cadastrar relógios (medidores)](artigo:relogios) e [Portarias](artigo:gerenciamento).
