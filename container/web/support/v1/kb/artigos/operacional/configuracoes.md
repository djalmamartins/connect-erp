---
codigo: AP-121
titulo: Regras de mudança
resumo: Define as regras de mudança de cada condomínio: dias e horários permitidos, antecedência mínima do pedido e taxa.
categoria: operacional
rota: /operacional/configuracoes
menu: Operacional > Rotina > Configurações
icone: list-outline
situacao: funciona
relacionados: agendamentos, contratos, seguros, lista-de-espera
palavras: configurações, operacional, AP-121
rascunho: nao
---

Define as regras de mudança de cada condomínio: dias e horários permitidos, antecedência mínima do pedido e taxa.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Configurações]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela configuracoes
rota: /operacional/configuracoes
condominio: residencial-jardim-das-acacias
legenda: Tela Configurações
1: menu | Configurações no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova configuração") | Botão {{Nova configuração}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Nova configuração}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Dias permitidos** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela configuracoes-nova-configuracao
rota: /operacional/configuracoes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova configuração
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="allowed_days"] | Dias permitidos (obrigatório)
3: main form:not([method=get]) [name="hours"] | Horário
4: main form:not([method=get]) [name="min_advance_days"] | Antecedência mínima (dias)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Dias permitidos** (obrigatório): Ex.: segunda a sábado.
- **Horário**: Ex.: 8h às 17h.
- **Antecedência mínima (dias)**.
- **Taxa**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Configurações de mudança**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Agendar mudanças](artigo:agendamentos), [Contratos com fornecedores](artigo:contratos) e [Seguros do condomínio](artigo:seguros).
