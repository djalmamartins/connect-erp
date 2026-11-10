---
codigo: AP-110
titulo: Manutenções preventivas e corretivas
resumo: Agenda as manutenções do condomínio, com o serviço, o fornecedor, a data prevista e a periodicidade (mensal, anual). Ao concluir, registre a data em Concluída em.
categoria: operacional
rota: /operacional/manutencoes
menu: Operacional > Rotina > Manutenções
icone: list-outline
situacao: funciona
relacionados: chamados, ocorrencias, vistorias, reservas-areas-comuns
palavras: manutenções, operacional, AP-110
rascunho: nao
---

Agenda as manutenções do condomínio, com o serviço, o fornecedor, a data prevista e a periodicidade (mensal, anual). Ao concluir, registre a data em **Concluída em**.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Manutenções]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela manutencoes
rota: /operacional/manutencoes
condominio: residencial-jardim-das-acacias
legenda: Tela Manutenções
1: menu | Manutenções no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova manutenção") | Botão {{Nova manutenção}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Periodicidade e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova manutenção}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Serviço**, **Data prevista**, **Periodicidade** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela manutencoes-nova-manutencao
rota: /operacional/manutencoes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova manutenção
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="service"] | Serviço (obrigatório)
3: main form:not([method=get]) [name="planned_on"] | Data prevista (obrigatório)
4: main form:not([method=get]) [name="frequency"] | Periodicidade (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Serviço** (obrigatório).
- **Fornecedor**.
- **Data prevista** (obrigatório).
- **Periodicidade** (obrigatório): Opções: Única, Mensal, Trimestral, Semestral e Anual.
- **Concluída em**.
- **Descrição**.
- **Situação** (obrigatório): Opções: Agendada, Em execução, Concluída e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Periodicidade** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Serviço**, **Condomínio**, **Fornecedor**, **Data prevista**, **Periodicidade** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> As manutenções atrasadas aparecem no Dashboard e nos alertas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Abrir e acompanhar chamados](artigo:chamados), [Registrar ocorrências](artigo:ocorrencias) e [Vistorias nos condomínios](artigo:vistorias).
