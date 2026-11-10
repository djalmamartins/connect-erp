---
codigo: AP-007
titulo: Cadastrar vagas, depósitos e boxes
resumo: Áreas vinculáveis são as que pertencem ou ficam reservadas a uma unidade: vagas de garagem, depósitos e boxes. Cadastre e vincule cada uma à unidade que usa.
categoria: condominios
rota: /condominios/areas-vinculaveis
menu: Condomínios > Áreas Vinculáveis
icone: list-outline
situacao: funciona
relacionados: areas-comuns, patrimonio, unidades, painel-da-unidade
palavras: áreas vinculáveis, condomínios, AP-007, vaga, garagem, depósito, box
rascunho: nao
---

Áreas vinculáveis são as que pertencem ou ficam reservadas a uma unidade: vagas de garagem, depósitos e boxes. Cadastre e vincule cada uma à unidade que usa.

## Onde fica

No menu lateral, abra [[Condomínios > Áreas Vinculáveis]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela areas-vinculaveis
rota: /condominios/areas-vinculaveis
condominio: residencial-jardim-das-acacias
legenda: Tela Áreas Vinculáveis
1: menu | Áreas Vinculáveis no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova área vinculável") | Botão {{Nova área vinculável}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova área vinculável}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Tipo**, **Identificação** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela areas-vinculaveis-nova-area-vinculavel
rota: /condominios/areas-vinculaveis/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova área vinculável
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
3: main form:not([method=get]) [name="identifier"] | Identificação (obrigatório)
4: main form:not([method=get]) [name="unit_id"] | Unidade vinculada
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Tipo** (obrigatório): Opções: Vaga de garagem, Depósito, Box e Outro.
- **Identificação** (obrigatório): Ex.: G1-023.
- **Unidade vinculada**: Vazio: livre.
- **Unidade vinculada (texto)**: Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Localização**: Ex.: Subsolo 1.
- **Fração ideal (%)**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Identificação**, **Condomínio**, **Tipo**, **Unidade vinculada (texto)** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar áreas comuns](artigo:areas-comuns), [Cadastrar o patrimônio do condomínio](artigo:patrimonio) e [Cadastrar unidades](artigo:unidades).
