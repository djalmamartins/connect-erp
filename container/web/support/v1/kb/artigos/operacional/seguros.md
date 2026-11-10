---
codigo: AP-118
titulo: Seguros do condomínio
resumo: As apólices do condomínio: seguradora, vigência, prêmio, cobertura e a apólice em PDF guardada no Disco Virtual. O vencimento próximo aparece nos alertas.
categoria: operacional
rota: /operacional/seguros
menu: Operacional > Rotina > Seguros
icone: list-outline
situacao: funciona
relacionados: lista-de-espera, contratos, agendamentos-pendentes, agendamentos
palavras: seguros, operacional, AP-118
rascunho: nao
---

As apólices do condomínio: seguradora, vigência, prêmio, cobertura e a apólice em PDF guardada no Disco Virtual. O vencimento próximo aparece nos alertas.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Seguros]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela seguros
rota: /operacional/seguros
condominio: residencial-jardim-das-acacias
legenda: Tela Seguros
1: menu | Seguros no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova apólice") | Botão {{Nova apólice}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova apólice}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Seguradora**, **Apólice**, **Início da vigência**, **Fim da vigência** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela seguros-nova-apolice
rota: /operacional/seguros/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova apólice
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="insurer"] | Seguradora (obrigatório)
3: main form:not([method=get]) [name="policy_number"] | Apólice (obrigatório)
4: main form:not([method=get]) [name="starts_on"] | Início da vigência (obrigatório)
5: main form:not([method=get]) [name="ends_on"] | Fim da vigência (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Corretora ou seguradora (fornecedor)**: Opções: Águas da Cidade, Elevadores Ascensão Ltda, Limpa Bem Serviços e Verde Vivo Jardinagem.
- **Seguradora** (obrigatório).
- **Apólice** (obrigatório).
- **Início da vigência** (obrigatório).
- **Fim da vigência** (obrigatório).
- **Prêmio**.
- **Cobertura**.
- **Observações**.
- **Situação** (obrigatório): Opções: Vigente e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Apólice**, **Condomínio**, **Corretora ou seguradora (fornecedor)**, **Seguradora**, **Fim da vigência**, **Prêmio** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lista de espera das áreas comuns](artigo:lista-de-espera), [Contratos com fornecedores](artigo:contratos) e [Aprovar reservas pendentes](artigo:agendamentos-pendentes).
