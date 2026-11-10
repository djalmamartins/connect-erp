---
codigo: AP-013
titulo: Cadastrar animais de estimação
resumo: Animais de cada unidade, com espécie, raça e vacinação, para o regulamento interno e para a portaria.
categoria: cadastros
rota: /cadastros/animais-de-estimacao
menu: Cadastros > Animais de Estimação
icone: list-outline
situacao: funciona
relacionados: visitantes, contatos-uteis, veiculos, advogados
palavras: animais de estimação, cadastros, AP-013, pet, cachorro, gato
rascunho: nao
---

Animais de cada unidade, com espécie, raça e vacinação, para o regulamento interno e para a portaria.

## Onde fica

No menu lateral, abra [[Cadastros > Animais de Estimação]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela animais-de-estimacao
rota: /cadastros/animais-de-estimacao
condominio: residencial-jardim-das-acacias
legenda: Tela Animais de Estimação
1: menu | Animais de Estimação no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo animal") | Botão {{Novo animal}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Espécie e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo animal}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Nome**, **Espécie** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela animais-de-estimacao-novo-animal
rota: /cadastros/animais-de-estimacao/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo animal
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade
3: main form:not([method=get]) [name="name"] | Nome (obrigatório)
4: main form:not([method=get]) [name="species"] | Espécie (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Unidade (texto)**: Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Nome** (obrigatório).
- **Espécie** (obrigatório): Opções: Cão, Gato, Ave e Outro.
- **Porte**: Opções: Pequeno, Médio e Grande.
- **Raça**.
- **Tutor**.
- **Vacinação em dia**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Espécie** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **Condomínio**, **Unidade (texto)**, **Espécie**, **Raça** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar visitantes autorizados](artigo:visitantes), [Cadastrar contatos úteis](artigo:contatos-uteis) e [Cadastrar veículos das unidades](artigo:veiculos).
