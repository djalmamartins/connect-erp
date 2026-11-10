---
codigo: AP-007
titulo: Cadastrar e gerenciar áreas Vinculáveis
resumo: Vagas de garagem, depósitos e boxes que podem ser vinculados a uma unidade.
categoria: condominios
rota: /condominios/areas-vinculaveis
menu: Condomínios > Áreas Vinculáveis
icone: list-outline
situacao: funciona
palavras: áreas vinculáveis, condomínios, AP-007
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Áreas Vinculáveis]].

```tela areas-vinculaveis
rota: /condominios/areas-vinculaveis
condominio: residencial-jardim-das-acacias
legenda: Tela Áreas Vinculáveis
1: menu | Áreas Vinculáveis no menu lateral
2: main :is(a,button):has-text("Nova área vinculável") | Botão {{Nova área vinculável}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main table thead | Lista com Identificação, Condomínio, Tipo, Unidade vinculada (texto) e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova área vinculável}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela areas-vinculaveis-novo
rota: /condominios/areas-vinculaveis/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de área vinculável
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Tipo** (obrigatório): opções: Vaga de garagem, Depósito, Box e Outro.
- **Identificação** (obrigatório): ex.: G1-023.
- **Unidade vinculada**: vazio: livre. Escolha entre as unidades do condomínio selecionado.
- **Unidade vinculada (texto)**: preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Localização**: ex.: Subsolo 1.
- **Fração ideal (%)**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

