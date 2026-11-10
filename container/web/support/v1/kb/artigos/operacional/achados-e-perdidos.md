---
codigo: AP-134
titulo: Cadastrar e gerenciar achados e Perdidos
resumo: Itens encontrados no condomínio e a quem foram devolvidos.
categoria: operacional
rota: /operacional/achados-e-perdidos
menu: Operacional > Portaria > Achados e Perdidos
icone: list-outline
situacao: funciona
palavras: achados e perdidos, operacional, AP-134
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Achados e Perdidos]].

```tela achados-e-perdidos
rota: /operacional/achados-e-perdidos
condominio: residencial-jardim-das-acacias
legenda: Tela Achados e Perdidos
1: menu | Achados e Perdidos no menu lateral
2: main :is(a,button):has-text("Novo item") | Botão {{Novo item}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Achados e perdidos
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo item}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela achados-e-perdidos-novo
rota: /operacional/achados-e-perdidos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de item
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Item** (obrigatório).
- **Local**.
- **Encontrado em** (obrigatório): no formato dia/mês/ano.
- **Encontrado por**.
- **Devolvido em**: no formato dia/mês/ano.
- **Devolvido a**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

