---
codigo: AP-131
titulo: Portarias
resumo: As portarias de cada condomínio: presencial, remota ou híbrida, os turnos e o responsável.
categoria: operacional
rota: /operacional/gerenciamento
menu: Operacional > Portaria > Gerenciamento
icone: list-outline
situacao: funciona
relacionados: previsao-de-visita, registro-de-acessos, achados-e-perdidos, entregas
palavras: gerenciamento, operacional, AP-131
rascunho: nao
---

As portarias de cada condomínio: presencial, remota ou híbrida, os turnos e o responsável.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Gerenciamento]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela gerenciamento
rota: /operacional/gerenciamento
condominio: residencial-jardim-das-acacias
legenda: Tela Gerenciamento
1: menu | Gerenciamento no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova portaria") | Botão {{Nova portaria}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
```

## Como cadastrar

1. Clique em {{Nova portaria}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Nome**, **Tipo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela gerenciamento-nova-portaria
rota: /operacional/gerenciamento/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova portaria
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="name"] | Nome (obrigatório)
3: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
4: main form:not([method=get]) [name="shifts"] | Turnos
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Nome** (obrigatório): Ex.: Portaria principal.
- **Tipo** (obrigatório): Opções: Presencial, Remota e Híbrida.
- **Turnos**.
- **Responsável**.
- **Telefone**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Portarias**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Visitas previstas](artigo:previsao-de-visita), [Registro de acessos da portaria](artigo:registro-de-acessos) e [Achados e perdidos](artigo:achados-e-perdidos).
