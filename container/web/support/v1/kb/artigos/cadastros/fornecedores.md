---
codigo: AP-010
titulo: Cadastrar fornecedores
resumo: Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios: empresa de limpeza, manutenção de elevador, concessionárias. O fornecedor aparece nas contas a pagar, nos contratos e na importação de notas fiscais.
categoria: cadastros
rota: /cadastros/fornecedores
menu: Cadastros > Fornecedores
icone: list-outline
situacao: funciona
relacionados: funcionarios, advogados, sindico-e-conselho, veiculos
palavras: fornecedores, cadastros, AP-010, prestador, empresa, cnpj, concessionária
rascunho: nao
---

Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios: empresa de limpeza, manutenção de elevador, concessionárias. O fornecedor aparece nas contas a pagar, nos contratos e na importação de notas fiscais.

## Onde fica

No menu lateral, abra [[Cadastros > Fornecedores]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela fornecedores
rota: /cadastros/fornecedores
condominio: residencial-jardim-das-acacias
legenda: Tela Fornecedores
1: menu | Fornecedores no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo fornecedor") | Botão {{Novo fornecedor}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Categoria e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo fornecedor}}, no alto da lista.
2. Preencha os campos obrigatórios: **Razão social ou nome**, **CNPJ/CPF**, **Categoria** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela fornecedores-novo-fornecedor
rota: /cadastros/fornecedores/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo fornecedor
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="legal_name"] | Razão social ou nome (obrigatório)
2: main form:not([method=get]) [name="document"] | CNPJ/CPF (obrigatório)
3: main form:not([method=get]) [name="trade_name"] | Nome fantasia
4: main form:not([method=get]) [name="category"] | Categoria (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Razão social ou nome** (obrigatório).
- **CNPJ/CPF** (obrigatório): Exemplo: CPF ou CNPJ.
- **Nome fantasia**.
- **Categoria** (obrigatório).
- **E-mail**: Exemplo: nome@exemplo.com.
- **Telefone**.
- **Banco (código)**.
- **Agência**.
- **Conta**.
- **Chave Pix**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Categoria** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Razão social ou nome**, **Cnpj/cpf**, **Categoria**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Cadastre o CNPJ certinho: a importação de NF-e encontra o fornecedor por ele.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar funcionários do condomínio](artigo:funcionarios), [Cadastrar advogados](artigo:advogados) e [Cadastrar síndico e conselho](artigo:sindico-e-conselho).
