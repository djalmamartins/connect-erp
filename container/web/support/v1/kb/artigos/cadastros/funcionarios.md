---
codigo: AP-009
titulo: Cadastrar e gerenciar funcionários
resumo: Funcionários próprios de cada condomínio.
categoria: cadastros
rota: /cadastros/funcionarios
menu: Cadastros > Funcionários
icone: list-outline
situacao: funciona
palavras: funcionários, cadastros, AP-009
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Funcionários]].

```tela funcionarios
rota: /cadastros/funcionarios
condominio: residencial-jardim-das-acacias
legenda: Tela Funcionários
1: menu | Funcionários no menu lateral
2: main :is(a,button):has-text("Novo funcionário") | Botão {{Novo funcionário}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Função, Situação
4: main table thead | Lista com Nome completo, Condomínio, Função, Admissão, Telefone e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo funcionário}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela funcionarios-novo
rota: /cadastros/funcionarios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de funcionário
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Função** (obrigatório): opções: Zelador, Porteiro, Auxiliar de limpeza, Jardineiro, Manutenção, Vigia, Administrativo e Outra.
- **Nome completo** (obrigatório).
- **CPF** (obrigatório): o sistema confere os dígitos do CPF.
- **Admissão** (obrigatório): no formato dia/mês/ano.
- **Desligamento**: no formato dia/mês/ano.
- **Turno**: opções: Manhã, Tarde, Noite, 12x36 e Comercial.
- **Salário**: em reais, por exemplo 1.250,00.
- **Telefone**.
- **E-mail**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

