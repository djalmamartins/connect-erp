---
codigo: AP-016
titulo: Cadastrar advogados
resumo: Advogados e escritórios parceiros que cuidam da cobrança judicial e da assessoria dos condomínios.
categoria: cadastros
rota: /cadastros/advogados
menu: Cadastros > Advogados
icone: list-outline
situacao: funciona
relacionados: cobranca-judicial, fornecedores, veiculos, funcionarios
palavras: advogados, cadastros, AP-016
rascunho: nao
---

Advogados e escritórios parceiros que cuidam da cobrança judicial e da assessoria dos condomínios.

## Onde fica

No menu lateral, abra [[Cadastros > Advogados]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela advogados
rota: /cadastros/advogados
condominio: residencial-jardim-das-acacias
legenda: Tela Advogados
1: menu | Advogados no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo advogado") | Botão {{Novo advogado}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo advogado}}, no alto da lista.
2. Preencha os campos obrigatórios: **Nome**, **OAB** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela advogados-novo-advogado
rota: /cadastros/advogados/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo advogado
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="name"] | Nome (obrigatório)
2: main form:not([method=get]) [name="oab"] | OAB (obrigatório)
3: main form:not([method=get]) [name="document"] | CPF
4: main form:not([method=get]) [name="firm"] | Escritório
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Nome** (obrigatório).
- **OAB** (obrigatório): UF e número, ex.: SP 123456.
- **CPF**.
- **Escritório**.
- **E-mail**: Exemplo: nome@exemplo.com.
- **Telefone**.
- **Honorários (%)**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **OAB**, **Escritório**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cobrança judicial](artigo:cobranca-judicial), [Cadastrar fornecedores](artigo:fornecedores) e [Cadastrar veículos das unidades](artigo:veiculos).
