---
codigo: AP-120
titulo: Agendar mudanças
resumo: Mudanças de entrada e saída, por dia e período. Só cabe um agendamento por período e dia em cada condomínio, seguindo as regras da configuração de mudança.
categoria: operacional
rota: /operacional/agendamentos
menu: Operacional > Rotina > Agendamentos
icone: list-outline
situacao: funciona
relacionados: mudancas, contratos, configuracoes, seguros
palavras: agendamentos, operacional, AP-120
rascunho: nao
---

Mudanças de entrada e saída, por dia e período. Só cabe um agendamento por período e dia em cada condomínio, seguindo as regras da configuração de mudança.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Agendamentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela agendamentos
rota: /operacional/agendamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Agendamentos
1: menu | Agendamentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo agendamento") | Botão {{Novo agendamento}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo agendamento}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Unidade**, **Morador**, **Tipo**, **Data**, **Período** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela agendamentos-novo-agendamento
rota: /operacional/agendamentos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo agendamento
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
3: main form:not([method=get]) [name="resident_name"] | Morador (obrigatório)
4: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
5: main form:not([method=get]) [name="scheduled_on"] | Data (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Morador** (obrigatório).
- **Tipo** (obrigatório): Opções: Entrada e Saída.
- **Data** (obrigatório).
- **Período** (obrigatório): Opções: Manhã e Tarde.
- **Observações**.
- **Situação** (obrigatório): Opções: Agendada, Realizada e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Morador**, **Condomínio**, **Unidade**, **Tipo**, **Data**, **Período** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de mudanças](artigo:mudancas), [Contratos com fornecedores](artigo:contratos) e [Regras de mudança](artigo:configuracoes).
