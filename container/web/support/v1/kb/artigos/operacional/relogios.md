---
codigo: AP-041
titulo: Cadastrar relógios (medidores)
resumo: Medidores de água, gás e energia de cada unidade. A leitura inicial é a base da primeira leitura do mês.
categoria: operacional
rota: /operacional/relogios
menu: Operacional > Leituras e consumo > Relógios
icone: list-outline
situacao: funciona
relacionados: leituras, tarifas
palavras: relógios, operacional, AP-041
rascunho: nao
---

Medidores de água, gás e energia de cada unidade. A leitura inicial é a base da primeira leitura do mês.

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Relógios]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relogios
rota: /operacional/relogios
condominio: residencial-jardim-das-acacias
legenda: Tela Relógios
1: menu | Relógios no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo relógio") | Botão {{Novo relógio}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
```

## Como cadastrar

1. Clique em {{Novo relógio}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Unidade**, **Tipo**, **Número do medidor** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela relogios-novo-relogio
rota: /operacional/relogios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo relógio
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
3: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
4: main form:not([method=get]) [name="number"] | Número do medidor (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Tipo** (obrigatório): Opções: Água, Gás e Energia.
- **Número do medidor** (obrigatório).
- **Instalado em**.
- **Leitura inicial**: Vazio = 0.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Relógios**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar as leituras do mês](artigo:leituras) e [Tarifas de consumo](artigo:tarifas).
