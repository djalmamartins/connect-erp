---
codigo: AP-112
titulo: Registrar ocorrências
resumo: Ocorrências do dia a dia do condomínio, como barulho, vazamento ou dano, com a unidade, a prioridade e o que foi feito. Quando precisar da administradora, abra também um chamado no Help Desk.
categoria: operacional
rota: /operacional/ocorrencias
menu: Operacional > Rotina > Ocorrências
icone: list-outline
situacao: funciona
relacionados: chamados, lancar-multa-avulsa, relatorio-de-ocorrencias, manutencoes
palavras: ocorrências, operacional, AP-112
rascunho: nao
---

Ocorrências do dia a dia do condomínio, como barulho, vazamento ou dano, com a unidade, a prioridade e o que foi feito. Quando precisar da administradora, abra também um chamado no Help Desk.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Ocorrências]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela ocorrencias
rota: /operacional/ocorrencias
condominio: residencial-jardim-das-acacias
legenda: Tela Ocorrências
1: menu | Ocorrências no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova ocorrência") | Botão {{Nova ocorrência}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Prioridade e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova ocorrência}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Assunto**, **Prioridade**, **Data**, **Descrição** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela ocorrencias-nova-ocorrencia
rota: /operacional/ocorrencias/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova ocorrência
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="subject"] | Assunto (obrigatório)
3: main form:not([method=get]) [name="priority"] | Prioridade (obrigatório)
4: main form:not([method=get]) [name="occurred_on"] | Data (obrigatório)
5: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Assunto** (obrigatório).
- **Prioridade** (obrigatório): Opções: Baixa, Média e Alta.
- **Data** (obrigatório).
- **Descrição** (obrigatório).
- **Situação** (obrigatório): Opções: Aberta, Em andamento e Resolvida.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Prioridade** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Assunto**, **Condomínio**, **Unidade**, **Prioridade**, **Data** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Abrir e acompanhar chamados](artigo:chamados), [Lançar uma multa avulsa](artigo:lancar-multa-avulsa) e [Relatório de ocorrências](artigo:relatorio-de-ocorrencias).
