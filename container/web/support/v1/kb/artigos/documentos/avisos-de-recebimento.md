---
codigo: AP-126
titulo: Cadastrar e gerenciar avisos de Recebimento
resumo: Correspondências com aviso de recebimento (AR): envio, entrega ou devolução, com o comprovante.
categoria: documentos
rota: /documentos/avisos-de-recebimento
menu: Documentos > Avisos de Recebimento
icone: list-outline
situacao: funciona
palavras: avisos de recebimento, documentos, AP-126
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Documentos > Avisos de Recebimento]].

```tela avisos-de-recebimento
rota: /documentos/avisos-de-recebimento
condominio: residencial-jardim-das-acacias
legenda: Tela Avisos de Recebimento
1: menu | Avisos de Recebimento no menu lateral
2: main :is(a,button):has-text("Novo aviso de recebimento") | Botão {{Novo aviso de recebimento}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Avisos de recebimento
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo aviso de recebimento}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela avisos-de-recebimento-novo
rota: /documentos/avisos-de-recebimento/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de aviso de recebimento
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Destinatário** (obrigatório).
- **Código de rastreio** (obrigatório).
- **Assunto**: ex.: notificação de multa.
- **Enviado em** (obrigatório): no formato dia/mês/ano.
- **Entregue em**: no formato dia/mês/ano.
- **Comprovante (AR)**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

