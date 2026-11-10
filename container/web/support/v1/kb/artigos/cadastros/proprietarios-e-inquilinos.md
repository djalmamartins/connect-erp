---
codigo: AP-005
titulo: Cadastrar proprietários e inquilinos
resumo: Cadastre as pessoas de cada unidade: proprietário, inquilino, morador e responsável financeiro. A mesma pessoa pode estar em várias unidades e em vários condomínios: pesquise antes de cadastrar e só acrescente o vínculo.
categoria: cadastros
rota: /cadastros/proprietarios-e-inquilinos
menu: Cadastros > Proprietários e inquilinos
icone: list-outline
situacao: funciona
relacionados: sindico-e-conselho, funcionarios, fornecedores, advogados
palavras: proprietários e inquilinos, cadastros, AP-005, morador, condômino, proprietário, inquilino, responsável financeiro, cpf
rascunho: nao
---

Cadastre as pessoas de cada unidade: proprietário, inquilino, morador e responsável financeiro. A mesma pessoa pode estar em várias unidades e em vários condomínios: pesquise antes de cadastrar e só acrescente o vínculo.

## Onde fica

No menu lateral, abra [[Cadastros > Proprietários e inquilinos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela proprietarios-e-inquilinos
rota: /cadastros/proprietarios-e-inquilinos
condominio: residencial-jardim-das-acacias
legenda: Tela Proprietários e inquilinos
1: menu | Proprietários e inquilinos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova pessoa") | Botão {{Nova pessoa}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Vínculo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova pessoa}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Vínculo**, **Nome completo ou razão social** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela proprietarios-e-inquilinos-nova-pessoa
rota: /cadastros/proprietarios-e-inquilinos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova pessoa
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade
3: main form:not([method=get]) [name="relationship"] | Vínculo (obrigatório)
4: main form:not([method=get]) [name="name"] | Nome completo ou razão social (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Unidade (texto)**: Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Vínculo** (obrigatório): Opções: Proprietário, Inquilino, Morador e Responsável financeiro.
- **Nome completo ou razão social** (obrigatório).
- **CPF/CNPJ**: Exemplo: CPF ou CNPJ.
- **E-mail**: Exemplo: nome@exemplo.com.
- **Telefone**.
- **Início do vínculo**.
- **Fim do vínculo**.
- **Recebe boleto por e-mail**.
- **Não quer receber comunicados por e-mail**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Vínculo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome completo ou razão social**, **Condomínio**, **Unidade (texto)**, **Vínculo**, **Cpf/cnpj**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> O responsável financeiro é quem recebe a cobrança. Marque também se a pessoa aceita cobrança e comunicados por e-mail.

> Para ligar uma pessoa já cadastrada a outra unidade, abra o cadastro dela e acrescente o vínculo.

>! CPF, e-mail e telefone são dados pessoais (LGPD): cadastre só o necessário e não envie listas para fora do sistema.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar síndico e conselho](artigo:sindico-e-conselho), [Cadastrar funcionários do condomínio](artigo:funcionarios) e [Cadastrar fornecedores](artigo:fornecedores).
