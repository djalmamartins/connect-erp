---
codigo: AP-041
titulo: Cadastrar e gerenciar relógios
resumo: Medidores de água, gás e energia de cada unidade. A leitura inicial é a base da primeira leitura.
categoria: operacional
rota: /operacional/relogios
menu: Operacional > Leituras e consumo > Relógios
icone: list-outline
situacao: funciona
palavras: relógios, operacional, AP-041
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Relógios]].

```tela relogios
rota: /operacional/relogios
condominio: residencial-jardim-das-acacias
legenda: Tela Relógios
1: menu | Relógios no menu lateral
2: main :is(a,button):has-text("Novo relógio") | Botão {{Novo relógio}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main .erp-card | Relógios
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo relógio}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela relogios-novo
rota: /operacional/relogios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de relógio
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Tipo** (obrigatório): opções: Água, Gás e Energia.
- **Número do medidor** (obrigatório).
- **Instalado em**: no formato dia/mês/ano.
- **Leitura inicial**: vazio = 0.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

