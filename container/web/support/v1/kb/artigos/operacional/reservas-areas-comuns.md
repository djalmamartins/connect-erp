---
codigo: AP-113
titulo: Reservas de áreas comuns
resumo: As reservas do salão de festas, churrasqueira e demais áreas, seguindo as regras de cada área: antecedência, horário, taxa e aprovação. Reserva que precisa de aprovação aparece em Agendamentos Pendentes.
categoria: operacional
rota: /operacional/reservas-areas-comuns
menu: Operacional > Rotina > Reservas Áreas Comuns
icone: list-outline
situacao: funciona
relacionados: areas-comuns, agendamentos-pendentes, lista-de-espera, ocorrencias
palavras: reservas áreas comuns, operacional, AP-113
rascunho: nao
---

As reservas do salão de festas, churrasqueira e demais áreas, seguindo as regras de cada área: antecedência, horário, taxa e aprovação. Reserva que precisa de aprovação aparece em Agendamentos Pendentes.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Reservas Áreas Comuns]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela reservas-areas-comuns
rota: /operacional/reservas-areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Reservas Áreas Comuns
1: menu | Reservas Áreas Comuns no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova reserva") | Botão {{Nova reserva}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova reserva}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Área comum**, **Unidade**, **Morador**, **Data** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela reservas-areas-comuns-nova-reserva
rota: /operacional/reservas-areas-comuns/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova reserva
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="common_area_id"] | Área comum (obrigatório)
3: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
4: main form:not([method=get]) [name="resident_name"] | Morador (obrigatório)
5: main form:not([method=get]) [name="reserved_on"] | Data (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Área comum** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Morador** (obrigatório).
- **Data** (obrigatório).
- **Das**.
- **Até**.
- **Taxa**: Vazio = taxa da área.
- **Observações**.
- **Situação** (obrigatório): Opções: Aguardando aprovação, Confirmada e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Morador**, **Condomínio**, **Área comum**, **Unidade**, **Data**, **Taxa** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar áreas comuns](artigo:areas-comuns), [Aprovar reservas pendentes](artigo:agendamentos-pendentes) e [Lista de espera das áreas comuns](artigo:lista-de-espera).
