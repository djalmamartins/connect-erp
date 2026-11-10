---
codigo: AP-135
titulo: Cadastrar e gerenciar entregas
resumo: Encomendas recebidas pela portaria. Informe a retirada para dar baixa.
categoria: operacional
rota: /operacional/entregas
menu: Operacional > Portaria > Entregas
icone: list-outline
situacao: funciona
palavras: entregas, operacional, AP-135
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Entregas]].

```tela entregas
rota: /operacional/entregas
condominio: residencial-jardim-das-acacias
legenda: Tela Entregas
1: menu | Entregas no menu lateral
2: main :is(a,button):has-text("Nova entrega") | Botão {{Nova entrega}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Transportadora, Situação
4: main .erp-card | Entregas
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova entrega}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela entregas-novo
rota: /operacional/entregas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de entrega
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Destinatário** (obrigatório).
- **Transportadora** (obrigatório): opções: Correios, Mercado Livre, Amazon e Outra.
- **Código de rastreio**.
- **Recebida em** (obrigatório): no formato dia/mês/ano.
- **Hora**.
- **Retirada em**: no formato dia/mês/ano.
- **Retirada por**.
- **Portaria**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

