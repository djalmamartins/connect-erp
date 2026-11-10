---
codigo: AP-014
titulo: Cadastrar síndico e conselho
resumo: Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, com o início e o fim do mandato. O síndico aparece nos documentos e nas assinaturas da prestação de contas.
categoria: cadastros
rota: /cadastros/sindico-e-conselho
menu: Cadastros > Síndico e conselho
icone: list-outline
situacao: funciona
relacionados: proprietarios-e-inquilinos, funcionarios, fornecedores, advogados
palavras: síndico e conselho, cadastros, AP-014, síndico, subsíndico, conselho fiscal, mandato
rascunho: nao
---

Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, com o início e o fim do mandato. O síndico aparece nos documentos e nas assinaturas da prestação de contas.

## Onde fica

No menu lateral, abra [[Cadastros > Síndico e conselho]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela sindico-e-conselho
rota: /cadastros/sindico-e-conselho
condominio: residencial-jardim-das-acacias
legenda: Tela Síndico e conselho
1: menu | Síndico e conselho no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo membro") | Botão {{Novo membro}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Cargo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo membro}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Cargo**, **Nome ou razão social**, **Início do mandato**, **Fim do mandato** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela sindico-e-conselho-novo-membro
rota: /cadastros/sindico-e-conselho/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo membro
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="role"] | Cargo (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome ou razão social (obrigatório)
4: main form:not([method=get]) [name="term_starts_on"] | Início do mandato (obrigatório)
5: main form:not([method=get]) [name="term_ends_on"] | Fim do mandato (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Cargo** (obrigatório): Opções: Síndico, Subsíndico, Conselheiro fiscal, Conselheiro consultivo e Suplente.
- **Nome ou razão social** (obrigatório).
- **CPF/CNPJ**: CNPJ para síndico profissional.
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Unidade (texto)**: Vazio para síndico profissional.
- **Início do mandato** (obrigatório).
- **Fim do mandato** (obrigatório).
- **E-mail**: Exemplo: nome@exemplo.com.
- **Telefone**.
- **Observações**: Ex.: ata da assembleia que elegeu.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Cargo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome ou razão social**, **Condomínio**, **Cargo**, **Fim do mandato**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Ao trocar de síndico, encerre o mandato anterior com a data de fim em vez de excluir, para manter o histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar proprietários e inquilinos](artigo:proprietarios-e-inquilinos), [Cadastrar funcionários do condomínio](artigo:funcionarios) e [Cadastrar fornecedores](artigo:fornecedores).
