---
codigo: AP-019
titulo: Cadastrar o patrimônio do condomínio
resumo: Registre os bens do condomínio, como móveis, equipamentos, eletrônicos e ferramentas, com valor, data de compra e onde estão. Ajuda no inventário, no seguro e na prestação de contas.
categoria: condominios
rota: /condominios/patrimonio
menu: Condomínios > Patrimônio
icone: list-outline
situacao: funciona
relacionados: areas-vinculaveis, areas-comuns, unidades, painel-da-unidade
palavras: patrimônio, condomínios, AP-019, bens, inventário, equipamentos
rascunho: nao
---

Registre os bens do condomínio, como móveis, equipamentos, eletrônicos e ferramentas, com valor, data de compra e onde estão. Ajuda no inventário, no seguro e na prestação de contas.

## Onde fica

No menu lateral, abra [[Condomínios > Patrimônio]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela patrimonio
rota: /condominios/patrimonio
condominio: residencial-jardim-das-acacias
legenda: Tela Patrimônio
1: menu | Patrimônio no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo bem") | Botão {{Novo bem}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Categoria e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo bem}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Descrição**, **Categoria**, **Estado** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela patrimonio-novo-bem
rota: /condominios/patrimonio/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo bem
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
3: main form:not([method=get]) [name="category"] | Categoria (obrigatório)
4: main form:not([method=get]) [name="condition"] | Estado (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Descrição** (obrigatório).
- **Número de patrimônio**.
- **Categoria** (obrigatório).
- **Estado** (obrigatório): Opções: Bom, Regular, Em manutenção e Inservível.
- **Data de aquisição**.
- **Valor de aquisição**.
- **Localização**.
- **Fornecedor**.
- **Nota fiscal nº**.
- **Observações**.
- **Situação** (obrigatório): Opções: Em uso e Baixado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Categoria** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Número de patrimônio**, **Categoria**, **Estado**, **Valor de aquisição** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar vagas, depósitos e boxes](artigo:areas-vinculaveis), [Cadastrar áreas comuns](artigo:areas-comuns) e [Cadastrar unidades](artigo:unidades).
