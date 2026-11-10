---
codigo: AP-005
titulo: Cadastrar e gerenciar proprietários e inquilinos
resumo: Proprietários, inquilinos, moradores e responsáveis financeiros de cada unidade.
categoria: cadastros
rota: /cadastros/proprietarios-e-inquilinos
menu: Cadastros > Proprietários e inquilinos
icone: list-outline
situacao: funciona
palavras: proprietários e inquilinos, cadastros, AP-005
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Proprietários e inquilinos]].

```tela proprietarios-e-inquilinos
rota: /cadastros/proprietarios-e-inquilinos
condominio: residencial-jardim-das-acacias
legenda: Tela Proprietários e inquilinos
1: menu | Proprietários e inquilinos no menu lateral
2: main :is(a,button):has-text("Nova pessoa") | Botão {{Nova pessoa}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Vínculo, Situação
4: main table thead | Lista com Nome completo ou razão social, Condomínio, Unidade (texto), Vínculo, Cpf/cnpj, Telefone e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova pessoa}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela proprietarios-e-inquilinos-novo
rota: /cadastros/proprietarios-e-inquilinos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de pessoa
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Unidade (texto)**: preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Vínculo** (obrigatório): opções: Proprietário, Inquilino, Morador e Responsável financeiro.
- **Nome completo ou razão social** (obrigatório).
- **CPF/CNPJ**: cPF ou CNPJ; o sistema confere os dígitos.
- **E-mail**.
- **Telefone**.
- **Início do vínculo**: no formato dia/mês/ano.
- **Fim do vínculo**: no formato dia/mês/ano.
- **Recebe boleto por e-mail**: marque para ativar.
- **Não quer receber comunicados por e-mail**: marque para ativar.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

