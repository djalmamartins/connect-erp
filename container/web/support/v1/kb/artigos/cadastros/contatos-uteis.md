---
codigo: AP-015
titulo: Cadastrar contatos úteis
resumo: Telefones úteis para moradores e portaria: plantão de elevador, eletricista, bombeiro, concessionárias. Sem condomínio, o contato vale para todos.
categoria: cadastros
rota: /cadastros/contatos-uteis
menu: Cadastros > Contatos Úteis
icone: list-outline
situacao: funciona
relacionados: animais-de-estimacao, visitantes, veiculos, advogados
palavras: contatos úteis, cadastros, AP-015
rascunho: nao
---

Telefones úteis para moradores e portaria: plantão de elevador, eletricista, bombeiro, concessionárias. Sem condomínio, o contato vale para todos.

## Onde fica

No menu lateral, abra [[Cadastros > Contatos Úteis]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contatos-uteis
rota: /cadastros/contatos-uteis
condominio: residencial-jardim-das-acacias
legenda: Tela Contatos Úteis
1: menu | Contatos Úteis no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo contato") | Botão {{Novo contato}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Serviço e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo contato}}, no alto da lista.
2. Preencha os campos obrigatórios: **Serviço**, **Nome**, **Telefone** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela contatos-uteis-novo-contato
rota: /cadastros/contatos-uteis/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo contato
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio
2: main form:not([method=get]) [name="service"] | Serviço (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome (obrigatório)
4: main form:not([method=get]) [name="phone"] | Telefone (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: vale para todos os condomínios.
- **Serviço** (obrigatório).
- **Nome** (obrigatório).
- **Telefone** (obrigatório).
- **E-mail**: Exemplo: nome@exemplo.com.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Serviço** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **Condomínio**, **Serviço**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar animais de estimação](artigo:animais-de-estimacao), [Cadastrar visitantes autorizados](artigo:visitantes) e [Cadastrar veículos das unidades](artigo:veiculos).
