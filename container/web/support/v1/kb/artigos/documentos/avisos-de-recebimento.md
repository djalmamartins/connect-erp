---
codigo: AP-126
titulo: Avisos de recebimento (AR)
resumo: Correspondências enviadas com aviso de recebimento: envio, entrega ou devolução, e o comprovante.
categoria: documentos
rota: /documentos/avisos-de-recebimento
menu: Documentos > Avisos de Recebimento
icone: list-outline
situacao: funciona
relacionados: comprovante-de-quitacao, copias-de-cheques, declaracao-de-inadimplencia, empenhos
palavras: avisos de recebimento, documentos, AP-126
rascunho: nao
---

Correspondências enviadas com aviso de recebimento: envio, entrega ou devolução, e o comprovante.

## Onde fica

No menu lateral, abra [[Documentos > Avisos de Recebimento]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela avisos-de-recebimento
rota: /documentos/avisos-de-recebimento
condominio: residencial-jardim-das-acacias
legenda: Tela Avisos de Recebimento
1: menu | Avisos de Recebimento no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo aviso de recebimento") | Botão {{Novo aviso de recebimento}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Novo aviso de recebimento}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Destinatário**, **Código de rastreio**, **Enviado em** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela avisos-de-recebimento-novo-aviso-de-recebimento
rota: /documentos/avisos-de-recebimento/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo aviso de recebimento
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="recipient"] | Destinatário (obrigatório)
3: main form:not([method=get]) [name="tracking_code"] | Código de rastreio (obrigatório)
4: main form:not([method=get]) [name="sent_on"] | Enviado em (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Destinatário** (obrigatório).
- **Código de rastreio** (obrigatório).
- **Assunto**: Ex.: notificação de multa.
- **Enviado em** (obrigatório).
- **Entregue em**.
- **Observações**.
- **Situação** (obrigatório): Opções: Em trânsito, Entregue e Devolvido.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Avisos de recebimento**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Emitir o comprovante de quitação](artigo:comprovante-de-quitacao), [Cópias de cheques](artigo:copias-de-cheques) e [Emitir a declaração de inadimplência](artigo:declaracao-de-inadimplencia).
