---
codigo: AP-164
titulo: Impostos retidos
resumo: Alíquotas de retenção (ISS, INSS, IR e outros) para conferir as notas de serviço. Sem condomínio, vale para todos. O sistema não calcula nem lança o imposto sozinho.
categoria: configuracoes
rota: /configuracoes/imposto
menu: Configurações > Financeiro e parâmetros > Imposto
icone: list-outline
situacao: funciona
relacionados: previsao-orcamentaria, indices, saldo-inicial-centro-de-custo, parametros
palavras: imposto, configurações, AP-164
rascunho: nao
---

Alíquotas de retenção (ISS, INSS, IR e outros) para conferir as notas de serviço. Sem condomínio, vale para todos. O sistema não calcula nem lança o imposto sozinho.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Imposto]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela imposto
rota: /configuracoes/imposto
condominio: residencial-jardim-das-acacias
legenda: Tela Imposto
1: menu | Imposto no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo imposto") | Botão {{Novo imposto}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Imposto e Situação
```

## Como cadastrar

1. Clique em {{Novo imposto}}, no alto da lista.
2. Preencha os campos obrigatórios: **Imposto**, **Nome**, **Alíquota (%)**, **Aplica-se a** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela imposto-novo-imposto
rota: /configuracoes/imposto/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo imposto
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="kind"] | Imposto (obrigatório)
2: main form:not([method=get]) [name="name"] | Nome (obrigatório)
3: main form:not([method=get]) [name="rate"] | Alíquota (%) (obrigatório)
4: main form:not([method=get]) [name="applies_to"] | Aplica-se a (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: padrão da Connect.
- **Imposto** (obrigatório): Opções: ISS, IRRF, INSS, PIS/COFINS/CSLL e Outro.
- **Nome** (obrigatório).
- **Alíquota (%)** (obrigatório).
- **Aplica-se a** (obrigatório): Opções: Serviços, Materiais e Serviços e materiais.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Imposto** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Impostos**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Previsão orçamentária](artigo:previsao-orcamentaria), [Índices e regra de cobrança](artigo:indices) e [Saldo inicial dos centros de custo](artigo:saldo-inicial-centro-de-custo).
