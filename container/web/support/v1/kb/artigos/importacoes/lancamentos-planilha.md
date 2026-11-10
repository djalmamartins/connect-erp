---
codigo: AP-144
titulo: Importar lançamentos de uma planilha
resumo: Importa receitas e despesas de uma planilha CSV ou XLSX. A prévia confere o arquivo inteiro; só dá para aplicar quando todas as linhas estão certas.
categoria: importacoes
rota: /importacoes/lancamentos-planilha
menu: Importações > Lançamentos (Planilha)
icone: cloud-upload-outline
situacao: funciona
relacionados: fracoes-ideais-planilha, lancamentos-por-unidade, inadimplentes-planilha, contas-a-pagar-planilha
palavras: lançamentos (planilha), importações, AP-144
rascunho: nao
---

Importa receitas e despesas de uma planilha CSV ou XLSX. A prévia confere o arquivo inteiro; só dá para aplicar quando todas as linhas estão certas.

## Onde fica

No menu lateral, abra [[Importações > Lançamentos (Planilha)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lancamentos-planilha
rota: /importacoes/lancamentos-planilha
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos (Planilha)
1: menu | Lançamentos (Planilha) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela lancamentos-planilha-form
rota: /importacoes/lancamentos-planilha
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

>> Veja também: [Importar frações ideais de uma planilha](artigo:fracoes-ideais-planilha), [Importar lançamentos por unidade](artigo:lancamentos-por-unidade) e [Importar inadimplentes de uma planilha](artigo:inadimplentes-planilha).
