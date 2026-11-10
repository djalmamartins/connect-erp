---
codigo: AP-135
titulo: Entregas na portaria
resumo: Encomendas recebidas pela portaria para cada unidade. Quando o morador retirar, informe a retirada para dar baixa.
categoria: operacional
rota: /operacional/entregas
menu: Operacional > Portaria > Entregas
icone: list-outline
situacao: funciona
relacionados: achados-e-perdidos, registros-de-entregas, registro-de-acessos, previsao-de-visita
palavras: entregas, operacional, AP-135
rascunho: nao
---

Encomendas recebidas pela portaria para cada unidade. Quando o morador retirar, informe a retirada para dar baixa.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Entregas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela entregas
rota: /operacional/entregas
condominio: residencial-jardim-das-acacias
legenda: Tela Entregas
1: menu | Entregas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova entrega") | Botão {{Nova entrega}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Transportadora e Situação
```

## Como cadastrar

1. Clique em {{Nova entrega}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Unidade**, **Destinatário**, **Transportadora**, **Recebida em** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela entregas-nova-entrega
rota: /operacional/entregas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova entrega
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
3: main form:not([method=get]) [name="recipient"] | Destinatário (obrigatório)
4: main form:not([method=get]) [name="carrier"] | Transportadora (obrigatório)
5: main form:not([method=get]) [name="received_on"] | Recebida em (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Destinatário** (obrigatório).
- **Transportadora** (obrigatório): Opções: Correios, Mercado Livre, Amazon e Outra.
- **Código de rastreio**.
- **Recebida em** (obrigatório).
- **Hora**.
- **Retirada em**.
- **Retirada por**.
- **Portaria**.
- **Observações**.
- **Situação** (obrigatório): Opções: Aguardando retirada, Retirada e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Transportadora** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Entregas**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Achados e perdidos](artigo:achados-e-perdidos), [Registros de entregas](artigo:registros-de-entregas) e [Registro de acessos da portaria](artigo:registro-de-acessos).
