---
codigo: AP-123
titulo: Emitir a certidão de débitos
resumo: Emite a certidão de débitos da unidade na data escolhida. Sem conta vencida, ela sai como "nada consta"; com conta vencida, lista as contas e os encargos. Muito pedida em venda e locação.
categoria: documentos
rota: /documentos/certidao-de-debitos
menu: Documentos > Certidão de Débitos
icone: ribbon-outline
situacao: funciona
relacionados: carta-de-anuencia, comprovante-de-quitacao, disco-virtual, declaracao-de-inadimplencia
palavras: certidão de débitos, documentos, AP-123, nada consta, certidão negativa, venda, locação
rascunho: nao
---

Emite a certidão de débitos da unidade na data escolhida. Sem conta vencida, ela sai como "nada consta"; com conta vencida, lista as contas e os encargos. Muito pedida em venda e locação.

## Onde fica

No menu lateral, abra [[Documentos > Certidão de Débitos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela certidao-de-debitos
rota: /documentos/certidao-de-debitos
condominio: residencial-jardim-das-acacias
legenda: Tela Certidão de Débitos
1: menu | Certidão de Débitos no menu lateral
2: main form:not([method=get]) | Emitir
```

## Emitir

O documento é gerado das contas a receber da unidade e fica gravado com um código de verificação. Depois de emitido, não muda; para corrigir, cancele e emita outro.

1. Preencha **Unidade** e **Data de referência**.
2. Clique em {{Emitir documento}}.

```tela certidao-de-debitos-form
rota: /documentos/certidao-de-debitos
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

> Cada documento tem um código de verificação. Quem recebeu confere a autenticidade no endereço público /verificar-certidao-debitos.

>! Depois de emitido, o documento não muda. Para corrigir, cancele e emita outro.

> Na tela: *Escolha a unidade acima e emita o primeiro.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Emitir a carta de anuência](artigo:carta-de-anuencia), [Emitir o comprovante de quitação](artigo:comprovante-de-quitacao) e [Disco Virtual: arquivos do condomínio](artigo:disco-virtual).
