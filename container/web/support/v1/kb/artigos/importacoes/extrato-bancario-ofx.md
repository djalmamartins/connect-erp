---
codigo: AP-140
titulo: Importar extrato Bancário (OFX)
resumo: Extrato da conta bancária em OFX. Cada linha procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O extrato não cria nem baixa lançamento: o que não bate fica pendente para você resolver.
categoria: importacoes
rota: /importacoes/extrato-bancario-ofx
menu: Importações > Extrato Bancário (OFX)
icone: cloud-upload-outline
situacao: funciona
palavras: extrato bancário (ofx), importações, AP-140
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Importações > Extrato Bancário (OFX)]].

```tela extrato-bancario-ofx
rota: /importacoes/extrato-bancario-ofx
condominio: residencial-jardim-das-acacias
legenda: Tela Extrato Bancário (OFX)
1: menu | Extrato Bancário (OFX) no menu lateral
```

## Como importar

1. Separe o arquivo no formato pedido na tela.
2. Escolha o condomínio e o arquivo e envie.
3. Confira a **pré-visualização**: o sistema mostra as linhas reconhecidas e aponta as que têm erro, antes de gravar qualquer coisa.
4. Confirme a importação. Ela é gravada de uma vez: se algo falhar, nada entra pela metade.

> **Dica:** o histórico em "Importações enviadas" mostra quem enviou cada arquivo, quando e o resultado.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Importações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

