---
codigo: AP-011
titulo: Cadastrar e gerenciar veículos
resumo: Veículos autorizados por unidade, para a portaria e o controle de vagas.
categoria: cadastros
rota: /cadastros/veiculos
menu: Cadastros > Veículos
icone: list-outline
situacao: funciona
palavras: veículos, cadastros, AP-011
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Veículos]].

```tela veiculos
rota: /cadastros/veiculos
condominio: residencial-jardim-das-acacias
legenda: Tela Veículos
1: menu | Veículos no menu lateral
2: main :is(a,button):has-text("Novo veículo") | Botão {{Novo veículo}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main table thead | Lista com Placa, Condomínio, Unidade (texto), Marca e modelo, Cor e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo veículo}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela veiculos-novo
rota: /cadastros/veiculos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de veículo
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Unidade (texto)**: preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Placa** (obrigatório).
- **Tipo** (obrigatório): opções: Carro, Moto, Utilitário e Outro.
- **Marca e modelo**.
- **Cor**.
- **Vaga**.
- **Responsável**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

