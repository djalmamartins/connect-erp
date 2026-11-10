---
codigo: AP-123
titulo: Emitir certidão de Débitos
resumo: Emite a certidão de débitos da unidade na data de referência. Sem conta vencida, a certidão sai como "nada consta"; com conta vencida, lista as contas com os encargos.
categoria: documentos
rota: /documentos/certidao-de-debitos
menu: Documentos > Certidão de Débitos
icone: ribbon-outline
situacao: funciona
palavras: certidão de débitos, documentos, AP-123
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Documentos > Certidão de Débitos]].

```tela certidao-de-debitos
rota: /documentos/certidao-de-debitos
condominio: residencial-jardim-das-acacias
legenda: Tela Certidão de Débitos
1: menu | Certidão de Débitos no menu lateral
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

