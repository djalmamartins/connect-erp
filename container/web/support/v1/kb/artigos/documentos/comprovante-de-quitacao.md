---
codigo: AP-125
titulo: Emitir comprovante de Quitação
resumo: Comprova que todas as contas da unidade até a competência escolhida estão pagas. Com alguma conta em aberto, não é emitido.
categoria: documentos
rota: /documentos/comprovante-de-quitacao
menu: Documentos > Comprovante de Quitação
icone: ribbon-outline
situacao: funciona
palavras: comprovante de quitação, documentos, AP-125
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Documentos > Comprovante de Quitação]].

```tela comprovante-de-quitacao
rota: /documentos/comprovante-de-quitacao
condominio: residencial-jardim-das-acacias
legenda: Tela Comprovante de Quitação
1: menu | Comprovante de Quitação no menu lateral
2: main .erp-card | Emitir
```

## Como emitir

1. Escolha unidade e data de referência.
2. Clique em {{Emitir documento}}.
3. O documento sai com um **código de verificação** impresso no rodapé.

## Conferir a autenticidade

Qualquer pessoa pode conferir um documento emitido pelo sistema na página pública de verificação, digitando o código impresso. O sistema responde se é autêntico, o tipo, a data e o condomínio, sem mostrar valores nem dados pessoais.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

