---
codigo: AP-145
titulo: Importar lançamentos por unidade
resumo: Importa receitas de cada unidade de uma planilha CSV ou XLSX, com a unidade em todas as linhas.
categoria: importacoes
rota: /importacoes/lancamentos-por-unidade
menu: Importações > Lançamentos por Unidade
icone: cloud-upload-outline
situacao: funciona
relacionados: lancamentos-planilha, contas-a-pagar-planilha, fracoes-ideais-planilha, inadimplentes-planilha
palavras: lançamentos por unidade, importações, AP-145
rascunho: nao
---

Importa receitas de cada unidade de uma planilha CSV ou XLSX, com a unidade em todas as linhas.

## Onde fica

No menu lateral, abra [[Importações > Lançamentos por Unidade]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lancamentos-por-unidade
rota: /importacoes/lancamentos-por-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos por Unidade
1: menu | Lançamentos por Unidade no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela lancamentos-por-unidade-form
rota: /importacoes/lancamentos-por-unidade
condominio: residencial-jardim-das-acacias
legenda: Enviar arquivo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="file"] | Arquivo (obrigatório)
2: main form:not([method=get]) button:has-text("Enviar e ver a prévia") | Botão {{Enviar e ver a prévia}}
```

### O que preencher

- **Arquivo** (obrigatório): Primeira linha com os nomes das colunas. Escolha o arquivo no seu computador.

## O que a lista mostra

Colunas: **Linha**, **Situação**, **Conteúdo** e **Erros**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Importações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Importar lançamentos de uma planilha](artigo:lancamentos-planilha), [Importar contas a pagar de uma planilha](artigo:contas-a-pagar-planilha) e [Importar frações ideais de uma planilha](artigo:fracoes-ideais-planilha).
