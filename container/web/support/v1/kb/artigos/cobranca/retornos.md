---
codigo: AP-038
titulo: Retornos importados e divergências
resumo: Lista os arquivos de retorno importados e a fila das ocorrências que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou com nosso número desconhecido. Resolva cada divergência dando a baixa certa ou ignorando com motivo.
categoria: cobranca
rota: /cobranca/retornos
menu: Cobrança > Retornos
icone: list-outline
situacao: funciona
relacionados: arquivo-de-retorno, boletos, cobranca-judicial
palavras: retornos, cobrança, AP-038
rascunho: nao
---

Lista os arquivos de retorno importados e a fila das ocorrências que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou com nosso número desconhecido. Resolva cada divergência dando a baixa certa ou ignorando com motivo.

## Onde fica

No menu lateral, abra [[Cobrança > Retornos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela retornos
rota: /cobranca/retornos
condominio: residencial-jardim-das-acacias
legenda: Tela Retornos
1: menu | Retornos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Importar retorno") | Botão {{Importar retorno}}
3: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Importar retorno}}: abre a tela para importar um novo arquivo de retorno.

## O que a lista mostra

Colunas: **Importado em**, **Arquivo**, **Conta bancária**, **Ocorrências**, **Aplicadas**, **Conciliação** e **Total**.

> Na tela: *Todas as ocorrências dos retornos importados deram baixa ou já foram revisadas.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Importar o arquivo de retorno do banco](artigo:arquivo-de-retorno), [Boletos](artigo:boletos) e [Cobrança judicial](artigo:cobranca-judicial).
