---
codigo: AP-038
titulo: Retornos
resumo: Arquivos de retorno importados e a fila das ocorrências que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou nosso número desconhecido. Corrija à mão e marque como revisada.
categoria: cobranca
rota: /cobranca/retornos
menu: Cobrança > Retornos
icone: list-outline
situacao: funciona
palavras: retornos, cobrança, AP-038
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Retornos]].

```tela retornos
rota: /cobranca/retornos
condominio: residencial-jardim-das-acacias
legenda: Tela Retornos
1: menu | Retornos no menu lateral
2: main :is(a,button):has-text("Importar retorno") | Botão {{Importar retorno}}
3: main table thead | Lista com Importado em, Arquivo, Conta bancária, Ocorrências, Aplicadas, Conciliação e Total
```

## Como usar

Os botões no alto da tela são {{Importar retorno}}.

### O que cada coluna mostra

- **Importado em**
- **Arquivo**
- **Conta bancária**
- **Ocorrências**
- **Aplicadas**
- **Conciliação**
- **Total**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

