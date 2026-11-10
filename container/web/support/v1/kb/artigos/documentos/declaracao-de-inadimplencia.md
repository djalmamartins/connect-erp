---
codigo: AP-124
titulo: Emitir a declaração de inadimplência
resumo: Declara as contas vencidas em aberto da unidade, com os encargos na data de referência. Só é emitida se houver conta vencida.
categoria: documentos
rota: /documentos/declaracao-de-inadimplencia
menu: Documentos > Declaração de Inadimplência
icone: ribbon-outline
situacao: funciona
relacionados: certidao-de-debitos, comprovante-de-quitacao, disco-virtual, avisos-de-recebimento
palavras: declaração de inadimplência, documentos, AP-124
rascunho: nao
---

Declara as contas vencidas em aberto da unidade, com os encargos na data de referência. Só é emitida se houver conta vencida.

## Onde fica

No menu lateral, abra [[Documentos > Declaração de Inadimplência]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela declaracao-de-inadimplencia
rota: /documentos/declaracao-de-inadimplencia
condominio: residencial-jardim-das-acacias
legenda: Tela Declaração de Inadimplência
1: menu | Declaração de Inadimplência no menu lateral
2: main form:not([method=get]) | Emitir
```

## Emitir

O documento é gerado das contas a receber da unidade e fica gravado com um código de verificação. Depois de emitido, não muda; para corrigir, cancele e emita outro.

1. Preencha **Unidade** e **Data de referência**.
2. Clique em {{Emitir documento}}.

```tela declaracao-de-inadimplencia-form
rota: /documentos/declaracao-de-inadimplencia
condominio: residencial-jardim-das-acacias
legenda: Emitir
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
2: main form:not([method=get]) [name="as_of"] | Data de referência (obrigatório)
3: main form:not([method=get]) button:has-text("Emitir documento") | Botão {{Emitir documento}}
```

### O que preencher

- **Unidade** (obrigatório).
- **Data de referência** (obrigatório).

>! Depois de emitido, o documento não muda. Para corrigir, cancele e emita outro.

> Na tela: *Escolha a unidade acima e emita o primeiro.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Emitir a certidão de débitos](artigo:certidao-de-debitos), [Emitir o comprovante de quitação](artigo:comprovante-de-quitacao) e [Disco Virtual: arquivos do condomínio](artigo:disco-virtual).
