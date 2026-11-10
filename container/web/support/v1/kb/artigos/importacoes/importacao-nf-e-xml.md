---
codigo: AP-141
titulo: Importação NF-e (xml)
resumo: Nota fiscal eletrônica (XML) de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida. A mesma nota não entra duas vezes. A assinatura digital não é conferida na SEFAZ.
categoria: importacoes
rota: /importacoes/importacao-nf-e-xml
menu: Importações > Importação NF-e (xml)
icone: cloud-upload-outline
situacao: funciona
palavras: importação nf-e (xml), importações, AP-141
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Importações > Importação NF-e (xml)]].

```tela importacao-nf-e-xml
rota: /importacoes/importacao-nf-e-xml
condominio: residencial-jardim-das-acacias
legenda: Tela Importação NF-e (xml)
1: menu | Importação NF-e (xml) no menu lateral
```

## Como importar

1. Separe o arquivo no formato pedido na tela.
2. Escolha o condomínio e o arquivo e envie.
3. Confira a **pré-visualização**: o sistema mostra as linhas reconhecidas e aponta as que têm erro, antes de gravar qualquer coisa.
4. Confirme a importação. Ela é gravada de uma vez: se algo falhar, nada entra pela metade.

> **Dica:** o histórico em "Importações enviadas" mostra quem enviou cada arquivo, quando e o resultado.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Importações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

