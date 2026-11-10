---
codigo: AP-008
titulo: Cadastrar áreas comuns
resumo: Salão de festas, churrasqueira, academia e outras áreas que os moradores podem reservar. Em cada área você define as regras da reserva: capacidade, antecedência, horário, taxa e se precisa de aprovação.
categoria: condominios
rota: /condominios/areas-comuns
menu: Condomínios > Áreas Comuns
icone: list-outline
situacao: funciona
relacionados: agendamentos-pendentes, lista-de-espera, unidades, areas-vinculaveis
palavras: áreas comuns, condomínios, AP-008, salão de festas, churrasqueira, academia, reserva
rascunho: nao
---

Salão de festas, churrasqueira, academia e outras áreas que os moradores podem reservar. Em cada área você define as regras da reserva: capacidade, antecedência, horário, taxa e se precisa de aprovação.

## Onde fica

No menu lateral, abra [[Condomínios > Áreas Comuns]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela areas-comuns
rota: /condominios/areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Áreas Comuns
1: menu | Áreas Comuns no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova área comum") | Botão {{Nova área comum}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Reserva e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova área comum}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Nome da área**, **Reserva** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela areas-comuns-nova-area-comum
rota: /condominios/areas-comuns/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova área comum
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="name"] | Nome da área (obrigatório)
3: main form:not([method=get]) [name="booking_mode"] | Reserva (obrigatório)
4: main form:not([method=get]) [name="capacity"] | Capacidade (pessoas)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Nome da área** (obrigatório).
- **Reserva** (obrigatório): Opções: Exige aprovação, Livre, Bloqueada e Não reservável.
- **Capacidade (pessoas)**.
- **Taxa de reserva**.
- **Antecedência mínima (dias)**.
- **Antecedência máxima (dias)**.
- **Abre às**.
- **Fecha às**.
- **Regras de uso**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Reserva** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome da área**, **Condomínio**, **Reserva**, **Capacidade (pessoas)**, **Taxa de reserva** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Com aprovação obrigatória, o pedido do morador cai em Agendamentos Pendentes até alguém confirmar.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Aprovar reservas pendentes](artigo:agendamentos-pendentes), [Lista de espera das áreas comuns](artigo:lista-de-espera) e [Cadastrar unidades](artigo:unidades).
