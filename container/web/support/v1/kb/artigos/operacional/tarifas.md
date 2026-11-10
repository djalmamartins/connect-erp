---
codigo: AP-043
titulo: Cadastrar e gerenciar tarifas
resumo: Faixas progressivas por tipo e vigência. Sem condomínio, vale o padrão da Connect; a tarifa do condomínio substitui o padrão.
categoria: operacional
rota: /operacional/tarifas
menu: Operacional > Leituras e consumo > Tarifas
icone: list-outline
situacao: funciona
palavras: tarifas, operacional, AP-043
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Tarifas]].

```tela tarifas
rota: /operacional/tarifas
condominio: residencial-jardim-das-acacias
legenda: Tela Tarifas
1: menu | Tarifas no menu lateral
2: main :is(a,button):has-text("Nova faixa de tarifa") | Botão {{Nova faixa de tarifa}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main .erp-card | Tarifas
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova faixa de tarifa}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela tarifas-novo
rota: /operacional/tarifas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de faixa de tarifa
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: tarifa padrão da Connect. Só aparecem os condomínios que a sua conta pode ver.
- **Tipo** (obrigatório): opções: Água, Gás e Energia.
- **Faixa** (obrigatório): ex.: Faixa 1.
- **De (m³ ou kWh)** (obrigatório).
- **Até**: vazio = sem limite.
- **Valor por m³ (ou kWh)** (obrigatório): em reais, por exemplo 1.250,00.
- **Vigência a partir de** (obrigatório): no formato dia/mês/ano.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

