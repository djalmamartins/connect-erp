---
codigo: AP-125
titulo: Emitir o comprovante de quitação
resumo: Comprova que todas as contas da unidade até a competência escolhida estão pagas. Se houver alguma conta em aberto, o comprovante não é emitido e a tela mostra qual falta.
categoria: documentos
rota: /documentos/comprovante-de-quitacao
menu: Documentos > Comprovante de Quitação
icone: ribbon-outline
situacao: funciona
relacionados: declaracao-de-inadimplencia, avisos-de-recebimento, certidao-de-debitos, copias-de-cheques
palavras: comprovante de quitação, documentos, AP-125
rascunho: nao
---

Comprova que todas as contas da unidade até a competência escolhida estão pagas. Se houver alguma conta em aberto, o comprovante não é emitido e a tela mostra qual falta.

## Onde fica

No menu lateral, abra [[Documentos > Comprovante de Quitação]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela comprovante-de-quitacao
rota: /documentos/comprovante-de-quitacao
condominio: residencial-jardim-das-acacias
legenda: Tela Comprovante de Quitação
1: menu | Comprovante de Quitação no menu lateral
2: main form:not([method=get]) | Emitir
```

## Emitir

O documento é gerado das contas a receber da unidade e fica gravado com um código de verificação. Depois de emitido, não muda; para corrigir, cancele e emita outro.

1. Preencha **Unidade**, **Data de referência** e **Competências até**.
2. Clique em {{Emitir documento}}.

```tela comprovante-de-quitacao-form
rota: /documentos/comprovante-de-quitacao
condominio: residencial-jardim-das-acacias
legenda: Emitir
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
2: main form:not([method=get]) [name="as_of"] | Data de referência (obrigatório)
3: main form:not([method=get]) :is(.erp-month:has([name="until_competence"]), [name="until_competence"]:not([type=hidden])) | Competências até (obrigatório)
4: main form:not([method=get]) button:has-text("Emitir documento") | Botão {{Emitir documento}}
```

### O que preencher

- **Unidade** (obrigatório).
- **Data de referência** (obrigatório).
- **Competências até** (obrigatório).

> Na tela: *Escolha a unidade acima e emita o primeiro.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Emitir a declaração de inadimplência](artigo:declaracao-de-inadimplencia), [Avisos de recebimento (AR)](artigo:avisos-de-recebimento) e [Emitir a certidão de débitos](artigo:certidao-de-debitos).
