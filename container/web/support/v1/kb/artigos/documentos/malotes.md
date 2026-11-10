---
codigo: AP-129
titulo: Malotes
resumo: Malotes de documentos entre o condomínio e a administradora: envio, recebimento e conferência do conteúdo.
categoria: documentos
rota: /documentos/malotes
menu: Documentos > Malotes
icone: list-outline
situacao: funciona
relacionados: empenhos, carta-de-anuencia, copias-de-cheques, avisos-de-recebimento
palavras: malotes, documentos, AP-129
rascunho: nao
---

Malotes de documentos entre o condomínio e a administradora: envio, recebimento e conferência do conteúdo.

## Onde fica

No menu lateral, abra [[Documentos > Malotes]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela malotes
rota: /documentos/malotes
condominio: residencial-jardim-das-acacias
legenda: Tela Malotes
1: menu | Malotes no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo malote") | Botão {{Novo malote}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Novo malote}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Enviado em**, **Portador**, **Documentos**, **Conteúdo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela malotes-novo-malote
rota: /documentos/malotes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo malote
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="sent_on"] | Enviado em (obrigatório)
3: main form:not([method=get]) [name="courier"] | Portador (obrigatório)
4: main form:not([method=get]) [name="documents_count"] | Documentos (obrigatório)
5: main form:not([method=get]) [name="contents"] | Conteúdo (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Enviado em** (obrigatório).
- **Portador** (obrigatório).
- **Documentos** (obrigatório).
- **Conteúdo** (obrigatório): Um documento por linha.
- **Recebido em**.
- **Conferido em**.
- **Observações**.
- **Situação** (obrigatório): Opções: Enviado, Recebido e Conferido.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Malotes**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Empenhos](artigo:empenhos), [Emitir a carta de anuência](artigo:carta-de-anuencia) e [Cópias de cheques](artigo:copias-de-cheques).
