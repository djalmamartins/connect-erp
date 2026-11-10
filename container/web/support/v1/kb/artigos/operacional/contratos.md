---
codigo: AP-119
titulo: Cadastrar e gerenciar contratos
resumo: Contratos com fornecedores: objeto, vigência, valor mensal, reajuste e o contrato assinado no Disco Virtual.
categoria: operacional
rota: /operacional/contratos
menu: Operacional > Rotina > Contratos
icone: list-outline
situacao: funciona
palavras: contratos, operacional, AP-119
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Contratos]].

```tela contratos
rota: /operacional/contratos
condominio: residencial-jardim-das-acacias
legenda: Tela Contratos
1: menu | Contratos no menu lateral
2: main :is(a,button):has-text("Novo contrato") | Botão {{Novo contrato}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main table thead | Lista com Objeto, Condomínio, Fornecedor, Fim, Valor mensal e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo contrato}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela contratos-novo
rota: /operacional/contratos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de contrato
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Fornecedor** (obrigatório).
- **Objeto** (obrigatório).
- **Início** (obrigatório): no formato dia/mês/ano.
- **Fim**: vazio = prazo indeterminado. No formato dia/mês/ano.
- **Valor mensal** (obrigatório): em reais, por exemplo 1.250,00.
- **Reajuste** (obrigatório): opções: IPCA, IGP-M e Sem reajuste.
- **Contrato assinado**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

