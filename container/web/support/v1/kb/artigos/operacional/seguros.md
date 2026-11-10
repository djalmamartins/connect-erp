---
codigo: AP-118
titulo: Cadastrar e gerenciar seguros
resumo: Apólices do condomínio: seguradora, vigência, prêmio, cobertura e a apólice em PDF no Disco Virtual.
categoria: operacional
rota: /operacional/seguros
menu: Operacional > Rotina > Seguros
icone: list-outline
situacao: funciona
palavras: seguros, operacional, AP-118
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Seguros]].

```tela seguros
rota: /operacional/seguros
condominio: residencial-jardim-das-acacias
legenda: Tela Seguros
1: menu | Seguros no menu lateral
2: main :is(a,button):has-text("Nova apólice") | Botão {{Nova apólice}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main table thead | Lista com Apólice, Condomínio, Corretora ou seguradora (fornecedor), Seguradora, Fim da vigência, Prêmio e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova apólice}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela seguros-novo
rota: /operacional/seguros/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de apólice
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Corretora ou seguradora (fornecedor)**.
- **Seguradora** (obrigatório).
- **Apólice** (obrigatório).
- **Início da vigência** (obrigatório): no formato dia/mês/ano.
- **Fim da vigência** (obrigatório): no formato dia/mês/ano.
- **Prêmio**: em reais, por exemplo 1.250,00.
- **Cobertura**: em reais, por exemplo 1.250,00.
- **Apólice (PDF)**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

