---
codigo: AP-006
titulo: Cadastrar e gerenciar unidades
resumo: Unidades de cada condomínio. Proprietários, inquilinos, conselho, veículos, visitantes e animais se vinculam a elas.
categoria: condominios
rota: /condominios/unidades
menu: Condomínios > Unidades
icone: list-outline
situacao: funciona
palavras: unidades, condomínios, AP-006
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Unidades]].

```tela unidades
rota: /condominios/unidades
condominio: residencial-jardim-das-acacias
legenda: Tela Unidades
1: menu | Unidades no menu lateral
2: main :is(a,button):has-text("Nova unidade") | Botão {{Nova unidade}}
3: main :is(a,button):has-text("Cadastro rápido") | Botão {{Cadastro rápido}}
4: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
5: main table thead | Lista com Unidade, Condomínio, Bloco ou torre, Tipo e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova unidade}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela unidades-novo
rota: /condominios/unidades/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de unidade
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Bloco ou torre**: vazio se o condomínio não tem blocos.
- **Unidade** (obrigatório): ex.: 101, Casa 3, Loja 2.
- **Tipo** (obrigatório): opções: Apartamento, Casa, Sala comercial, Loja, Garagem e Outro.
- **Andar**.
- **Fração ideal**: entre 0 e 1, ex.: 0,012500.
- **Área privativa (m²)**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

> **Atenção:** um cadastro usado por outros (por exemplo, unidade com lançamentos ou pessoas vinculadas) não pode ser excluído. Marque-o como inativo para tirá-lo das listas sem perder o histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

