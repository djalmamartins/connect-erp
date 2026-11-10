---
codigo: AP-134
titulo: Achados e perdidos
resumo: Itens encontrados no condomínio, onde estão guardados e a quem foram devolvidos.
categoria: operacional
rota: /operacional/achados-e-perdidos
menu: Operacional > Portaria > Achados e Perdidos
icone: list-outline
situacao: funciona
relacionados: registro-de-acessos, entregas, previsao-de-visita, registros-de-entregas
palavras: achados e perdidos, operacional, AP-134
rascunho: nao
---

Itens encontrados no condomínio, onde estão guardados e a quem foram devolvidos.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Achados e Perdidos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela achados-e-perdidos
rota: /operacional/achados-e-perdidos
condominio: residencial-jardim-das-acacias
legenda: Tela Achados e Perdidos
1: menu | Achados e Perdidos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo item") | Botão {{Novo item}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Novo item}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Item**, **Encontrado em** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela achados-e-perdidos-novo-item
rota: /operacional/achados-e-perdidos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo item
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="item"] | Item (obrigatório)
3: main form:not([method=get]) [name="location"] | Local
4: main form:not([method=get]) [name="found_on"] | Encontrado em (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Item** (obrigatório).
- **Local**.
- **Encontrado em** (obrigatório).
- **Encontrado por**.
- **Devolvido em**.
- **Devolvido a**.
- **Observações**.
- **Situação** (obrigatório): Opções: Guardado, Devolvido e Descartado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Achados e perdidos**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Registro de acessos da portaria](artigo:registro-de-acessos), [Entregas na portaria](artigo:entregas) e [Visitas previstas](artigo:previsao-de-visita).
