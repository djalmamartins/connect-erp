---
codigo: AP-110
titulo: Cadastrar e gerenciar manutenções
resumo: Manutenções preventivas e corretivas: serviço, fornecedor, data prevista, periodicidade e conclusão.
categoria: operacional
rota: /operacional/manutencoes
menu: Operacional > Rotina > Manutenções
icone: list-outline
situacao: funciona
palavras: manutenções, operacional, AP-110
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Manutenções]].

```tela manutencoes
rota: /operacional/manutencoes
condominio: residencial-jardim-das-acacias
legenda: Tela Manutenções
1: menu | Manutenções no menu lateral
2: main :is(a,button):has-text("Nova manutenção") | Botão {{Nova manutenção}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Periodicidade, Situação
4: main table thead | Lista com Serviço, Condomínio, Fornecedor, Data prevista, Periodicidade e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova manutenção}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela manutencoes-novo
rota: /operacional/manutencoes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de manutenção
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Serviço** (obrigatório).
- **Fornecedor**.
- **Data prevista** (obrigatório): no formato dia/mês/ano.
- **Periodicidade** (obrigatório): opções: Única, Mensal, Trimestral, Semestral e Anual.
- **Concluída em**: no formato dia/mês/ano.
- **Documento (laudo, nota)**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Descrição**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

