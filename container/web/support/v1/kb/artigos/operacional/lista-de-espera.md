---
codigo: AP-115
titulo: Lista de espera das áreas comuns
resumo: Quem quer uma área comum numa data já reservada entra na lista de espera, pela ordem de inscrição. Se a reserva for cancelada ou recusada, o primeiro da lista é avisado por e-mail.
categoria: operacional
rota: /operacional/lista-de-espera
menu: Operacional > Rotina > Lista de espera
icone: list-outline
situacao: funciona
relacionados: agendamentos-pendentes, seguros, reservas-areas-comuns, contratos
palavras: lista de espera, operacional, AP-115
rascunho: nao
---

Quem quer uma área comum numa data já reservada entra na lista de espera, pela ordem de inscrição. Se a reserva for cancelada ou recusada, o primeiro da lista é avisado por e-mail.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Lista de espera]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lista-de-espera
rota: /operacional/lista-de-espera
condominio: residencial-jardim-das-acacias
legenda: Tela Lista de espera
1: menu | Lista de espera no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova inscrição") | Botão {{Nova inscrição}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Nova inscrição}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Área comum**, **Unidade**, **Morador**, **Data desejada** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela lista-de-espera-nova-inscricao
rota: /operacional/lista-de-espera/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova inscrição
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="common_area_id"] | Área comum (obrigatório)
3: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
4: main form:not([method=get]) [name="resident_name"] | Morador (obrigatório)
5: main form:not([method=get]) [name="desired_on"] | Data desejada (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Área comum** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Morador** (obrigatório).
- **Data desejada** (obrigatório).
- **Observações**.
- **Situação** (obrigatório): Opções: Aguardando, Atendida e Desistiu.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Lista de espera**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Aprovar reservas pendentes](artigo:agendamentos-pendentes), [Seguros do condomínio](artigo:seguros) e [Reservas de áreas comuns](artigo:reservas-areas-comuns).
