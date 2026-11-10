---
codigo: AP-036
titulo: Importar arquivo de Retorno
resumo: Importe o arquivo de retorno do banco para registrar os boletos e dar baixa nos pagamentos. Pagamento de valor diferente ou título desconhecido vai para a fila de divergências.
categoria: cobranca
rota: /cobranca/arquivo-de-retorno
menu: Cobrança > Arquivo de Retorno
icone: cloud-upload-outline
situacao: funciona
palavras: arquivo de retorno, cobrança, AP-036
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Arquivo de Retorno]].

```tela arquivo-de-retorno
rota: /cobranca/arquivo-de-retorno
condominio: residencial-jardim-das-acacias
legenda: Tela Arquivo de Retorno
1: menu | Arquivo de Retorno no menu lateral
2: main :is(a,button):has-text("Divergências") | Botão {{Divergências}}
3: main .erp-card | Arquivo de retorno
```

## Como importar

1. Separe o arquivo no formato pedido na tela.
2. Escolha o condomínio e o arquivo e envie.
3. Confira a **pré-visualização**: o sistema mostra as linhas reconhecidas e aponta as que têm erro, antes de gravar qualquer coisa.
4. Confirme a importação. Ela é gravada de uma vez: se algo falhar, nada entra pela metade.

> **Dica:** o histórico em "Importações enviadas" mostra quem enviou cada arquivo, quando e o resultado.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

