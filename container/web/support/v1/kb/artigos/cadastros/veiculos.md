---
codigo: AP-011
titulo: Cadastrar veículos das unidades
resumo: Veículos autorizados de cada unidade, com placa, modelo e cor, para a portaria liberar a entrada e para o controle de vagas.
categoria: cadastros
rota: /cadastros/veiculos
menu: Cadastros > Veículos
icone: list-outline
situacao: funciona
relacionados: advogados, visitantes, fornecedores, animais-de-estimacao
palavras: veículos, cadastros, AP-011, carro, moto, placa
rascunho: nao
---

Veículos autorizados de cada unidade, com placa, modelo e cor, para a portaria liberar a entrada e para o controle de vagas.

## Onde fica

No menu lateral, abra [[Cadastros > Veículos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela veiculos
rota: /cadastros/veiculos
condominio: residencial-jardim-das-acacias
legenda: Tela Veículos
1: menu | Veículos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo veículo") | Botão {{Novo veículo}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo veículo}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Placa**, **Tipo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela veiculos-novo-veiculo
rota: /cadastros/veiculos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo veículo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade
3: main form:not([method=get]) [name="plate"] | Placa (obrigatório)
4: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Unidade (texto)**: Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Placa** (obrigatório): Exemplo: ABC1D23.
- **Tipo** (obrigatório): Opções: Carro, Moto, Utilitário e Outro.
- **Marca e modelo**.
- **Cor**.
- **Vaga**.
- **Responsável**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Placa**, **Condomínio**, **Unidade (texto)**, **Marca e modelo**, **COR** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar advogados](artigo:advogados), [Cadastrar visitantes autorizados](artigo:visitantes) e [Cadastrar fornecedores](artigo:fornecedores).
