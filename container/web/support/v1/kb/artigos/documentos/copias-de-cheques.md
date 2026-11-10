---
codigo: AP-127
titulo: Cadastrar e gerenciar cópias de Cheques
resumo: Cheques emitidos pelo condomínio, com a imagem guardada no Disco Virtual.
categoria: documentos
rota: /documentos/copias-de-cheques
menu: Documentos > Cópias de Cheques
icone: list-outline
situacao: funciona
palavras: cópias de cheques, documentos, AP-127
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Documentos > Cópias de Cheques]].

```tela copias-de-cheques
rota: /documentos/copias-de-cheques
condominio: residencial-jardim-das-acacias
legenda: Tela Cópias de Cheques
1: menu | Cópias de Cheques no menu lateral
2: main :is(a,button):has-text("Nova cópia de cheque") | Botão {{Nova cópia de cheque}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Cópias de cheques
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova cópia de cheque}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela copias-de-cheques-novo
rota: /documentos/copias-de-cheques/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de cópia de cheque
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Conta bancária** (obrigatório).
- **Número do cheque** (obrigatório).
- **Data** (obrigatório): no formato dia/mês/ano.
- **Favorecido** (obrigatório).
- **Valor** (obrigatório): em reais, por exemplo 1.250,00.
- **Imagem do cheque** (obrigatório): nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Lançamento**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

