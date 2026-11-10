---
codigo: AP-006
titulo: Cadastrar unidades
resumo: Cada apartamento, casa, sala ou loja é uma unidade. As pessoas (proprietários e inquilinos), os veículos, os animais, as cobranças e as reservas ficam ligados a ela. Cadastre uma por vez ou o prédio inteiro de uma vez pelo cadastro rápido.
categoria: condominios
rota: /condominios/unidades
menu: Condomínios > Unidades
icone: list-outline
situacao: funciona
relacionados: painel-da-unidade, areas-comuns, painel-do-condominio, areas-vinculaveis
palavras: unidades, condomínios, AP-006, proprietário, inquilino, pesquisar, apartamento, casa, sala, loja, cadastro rápido, fração ideal
rascunho: nao
---

Cada apartamento, casa, sala ou loja é uma unidade. As pessoas (proprietários e inquilinos), os veículos, os animais, as cobranças e as reservas ficam ligados a ela. Cadastre uma por vez ou o prédio inteiro de uma vez pelo cadastro rápido.

## Onde fica

No menu lateral, abra [[Condomínios > Unidades]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela unidades
rota: /condominios/unidades
condominio: residencial-jardim-das-acacias
legenda: Tela Unidades
1: menu | Unidades no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova unidade") | Botão {{Nova unidade}}
3: main .erp-page-head__actions :is(a,button):has-text("Cadastro rápido") | Botão {{Cadastro rápido}}
4: main .erp-page-head__actions :is(a,button):has-text("Fração ideal e área") | Botão {{Fração ideal e área}}
5: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
6: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova unidade}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Unidade**, **Tipo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela unidades-nova-unidade
rota: /condominios/unidades/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova unidade
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="block"] | Bloco ou torre
3: main form:not([method=get]) [name="number"] | Unidade (obrigatório)
4: main form:not([method=get]) [name="type"] | Tipo (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Bloco ou torre**: Vazio se o condomínio não tem blocos.
- **Unidade** (obrigatório): Ex.: 101, Casa 3, Loja 2.
- **Tipo** (obrigatório): Opções: Apartamento, Casa, Sala comercial, Loja, Garagem e Outro.
- **Andar**.
- **Fração ideal**: Entre 0 e 1, ex.: 0,012500.
- **Área privativa (m²)**.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Outros botões da tela

- {{Fração ideal e área}}: abre a grade para preencher a fração ideal e a área privativa de todas as unidades juntas.
- {{Cadastro rápido}}: gera todas as unidades de uma vez pela sequência de andares (ex.: 101 a 1208, 8 por andar), com pré-visualização antes de criar.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

Em **Pesquisar**, digite o número da unidade, o bloco ou o nome do proprietário ou do inquilino. O CPF ou CNPJ da pessoa também funciona. Um proprietário com várias unidades traz todas elas na mesma pesquisa.

## O que a lista mostra

Colunas: **Unidade**, **Condomínio**, **Bloco ou torre**, **Tipo**, **Proprietário**, **Inquilino** e **Situação**.

Proprietário e inquilino vêm de [[Cadastros > Proprietários e inquilinos]]. A lista mostra só os vínculos ativos: um inquilino com data de fim já passada ou inativado sai da coluna. As colunas aparecem para quem também pode ver o cadastro de proprietários e inquilinos.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> A fração ideal pode ficar para depois: ela é usada no rateio de despesas e pode ser dividida igualmente ou pela área na grade de frações.

>! Unidade inativa sai das cobranças novas, mas o histórico continua. Prefira inativar a excluir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Painel da unidade](artigo:painel-da-unidade), [Cadastrar áreas comuns](artigo:areas-comuns) e [Painel do condomínio](artigo:painel-do-condominio).
