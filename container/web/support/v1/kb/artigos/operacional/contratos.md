---
codigo: AP-119
titulo: Contratos com fornecedores
resumo: Contratos do condomínio com fornecedores: objeto, vigência, valor mensal, reajuste e o contrato assinado no Disco Virtual. O vencimento próximo aparece nos alertas.
categoria: operacional
rota: /operacional/contratos
menu: Operacional > Rotina > Contratos
icone: list-outline
situacao: funciona
relacionados: seguros, agendamentos, lista-de-espera, configuracoes
palavras: contratos, operacional, AP-119
rascunho: nao
---

Contratos do condomínio com fornecedores: objeto, vigência, valor mensal, reajuste e o contrato assinado no Disco Virtual. O vencimento próximo aparece nos alertas.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Contratos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contratos
rota: /operacional/contratos
condominio: residencial-jardim-das-acacias
legenda: Tela Contratos
1: menu | Contratos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo contrato") | Botão {{Novo contrato}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo contrato}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Fornecedor**, **Objeto**, **Início**, **Valor mensal**, **Reajuste** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela contratos-novo-contrato
rota: /operacional/contratos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo contrato
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="supplier_id"] | Fornecedor (obrigatório)
3: main form:not([method=get]) [name="object"] | Objeto (obrigatório)
4: main form:not([method=get]) [name="starts_on"] | Início (obrigatório)
5: main form:not([method=get]) [name="monthly_cents"] | Valor mensal (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Fornecedor** (obrigatório).
- **Objeto** (obrigatório).
- **Início** (obrigatório).
- **Fim**: Vazio = prazo indeterminado.
- **Valor mensal** (obrigatório).
- **Reajuste** (obrigatório): Opções: IPCA, IGP-M e Sem reajuste.
- **Observações**.
- **Situação** (obrigatório): Opções: Vigente, Encerrado e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Objeto**, **Condomínio**, **Fornecedor**, **FIM**, **Valor mensal** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Seguros do condomínio](artigo:seguros), [Agendar mudanças](artigo:agendamentos) e [Lista de espera das áreas comuns](artigo:lista-de-espera).
