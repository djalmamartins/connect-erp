---
codigo: AP-127
titulo: Cópias de cheques
resumo: Cheques emitidos pelo condomínio, com a imagem guardada no Disco Virtual.
categoria: documentos
rota: /documentos/copias-de-cheques
menu: Documentos > Cópias de Cheques
icone: list-outline
situacao: funciona
relacionados: avisos-de-recebimento, empenhos, comprovante-de-quitacao, malotes
palavras: cópias de cheques, documentos, AP-127
rascunho: nao
---

Cheques emitidos pelo condomínio, com a imagem guardada no Disco Virtual.

## Onde fica

No menu lateral, abra [[Documentos > Cópias de Cheques]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela copias-de-cheques
rota: /documentos/copias-de-cheques
condominio: residencial-jardim-das-acacias
legenda: Tela Cópias de Cheques
1: menu | Cópias de Cheques no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova cópia de cheque") | Botão {{Nova cópia de cheque}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Nova cópia de cheque}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Conta bancária**, **Número do cheque**, **Data**, **Favorecido**, **Valor** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela copias-de-cheques-nova-copia-de-cheque
rota: /documentos/copias-de-cheques/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova cópia de cheque
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="bank_account_id"] | Conta bancária (obrigatório)
3: main form:not([method=get]) [name="check_number"] | Número do cheque (obrigatório)
4: main form:not([method=get]) [name="issued_on"] | Data (obrigatório)
5: main form:not([method=get]) [name="payee"] | Favorecido (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Conta bancária** (obrigatório).
- **Número do cheque** (obrigatório).
- **Data** (obrigatório).
- **Favorecido** (obrigatório).
- **Valor** (obrigatório).
- **Lançamento**.
- **Observações**.
- **Situação** (obrigatório): Opções: Emitido e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Cópias de cheques**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Avisos de recebimento (AR)](artigo:avisos-de-recebimento), [Empenhos](artigo:empenhos) e [Emitir o comprovante de quitação](artigo:comprovante-de-quitacao).
