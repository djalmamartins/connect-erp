---
codigo: AP-009
titulo: Cadastrar funcionários do condomínio
resumo: Funcionários próprios do condomínio, como porteiros, zeladores e faxineiros, com função, contato e admissão.
categoria: cadastros
rota: /cadastros/funcionarios
menu: Cadastros > Funcionários
icone: list-outline
situacao: funciona
relacionados: sindico-e-conselho, fornecedores, proprietarios-e-inquilinos, advogados
palavras: funcionários, cadastros, AP-009, porteiro, zelador, colaborador
rascunho: nao
---

Funcionários próprios do condomínio, como porteiros, zeladores e faxineiros, com função, contato e admissão.

## Onde fica

No menu lateral, abra [[Cadastros > Funcionários]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela funcionarios
rota: /cadastros/funcionarios
condominio: residencial-jardim-das-acacias
legenda: Tela Funcionários
1: menu | Funcionários no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo funcionário") | Botão {{Novo funcionário}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Função e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo funcionário}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Função**, **Nome completo**, **CPF**, **Admissão** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela funcionarios-novo-funcionario
rota: /cadastros/funcionarios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo funcionário
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="job"] | Função (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome completo (obrigatório)
4: main form:not([method=get]) [name="document"] | CPF (obrigatório)
5: main form:not([method=get]) [name="hired_on"] | Admissão (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Função** (obrigatório).
- **Nome completo** (obrigatório).
- **CPF** (obrigatório).
- **Admissão** (obrigatório).
- **Desligamento**.
- **Turno**: Opções: Manhã, Tarde, Noite, 12x36 e Comercial.
- **Salário**.
- **Telefone**.
- **E-mail**: Exemplo: nome@exemplo.com.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Função** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome completo**, **Condomínio**, **Função**, **Admissão**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar síndico e conselho](artigo:sindico-e-conselho), [Cadastrar fornecedores](artigo:fornecedores) e [Cadastrar proprietários e inquilinos](artigo:proprietarios-e-inquilinos).
