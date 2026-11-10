---
codigo: AP-129
titulo: Cadastrar e gerenciar malotes
resumo: Malotes de documentos entre o condomínio e a administradora: envio, recebimento e conferência.
categoria: documentos
rota: /documentos/malotes
menu: Documentos > Malotes
icone: list-outline
situacao: funciona
palavras: malotes, documentos, AP-129
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Documentos > Malotes]].

```tela malotes
rota: /documentos/malotes
condominio: residencial-jardim-das-acacias
legenda: Tela Malotes
1: menu | Malotes no menu lateral
2: main :is(a,button):has-text("Novo malote") | Botão {{Novo malote}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Malotes
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo malote}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela malotes-novo
rota: /documentos/malotes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de malote
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Enviado em** (obrigatório): no formato dia/mês/ano.
- **Portador** (obrigatório).
- **Documentos** (obrigatório).
- **Conteúdo** (obrigatório): um documento por linha.
- **Recebido em**: no formato dia/mês/ano.
- **Conferido em**: no formato dia/mês/ano.
- **Protocolo**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

