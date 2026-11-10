---
codigo: AP-146
titulo: Importar contas a pagar de uma planilha
resumo: Importa despesas a pagar de uma planilha CSV ou XLSX, com o fornecedor opcional (pelo CNPJ ou CPF).
categoria: importacoes
rota: /importacoes/contas-a-pagar-planilha
menu: Importações > Contas a pagar (Planilha)
icone: cloud-upload-outline
situacao: funciona
relacionados: lancamentos-por-unidade, lancamentos-planilha, fracoes-ideais-planilha, inadimplentes-planilha
palavras: contas a pagar (planilha), importações, AP-146
rascunho: nao
---

Importa despesas a pagar de uma planilha CSV ou XLSX, com o fornecedor opcional (pelo CNPJ ou CPF).

## Onde fica

No menu lateral, abra [[Importações > Contas a pagar (Planilha)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contas-a-pagar-planilha
rota: /importacoes/contas-a-pagar-planilha
condominio: residencial-jardim-das-acacias
legenda: Tela Contas a pagar (Planilha)
1: menu | Contas a pagar (Planilha) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela contas-a-pagar-planilha-form
rota: /importacoes/contas-a-pagar-planilha
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

>> Veja também: [Importar lançamentos por unidade](artigo:lancamentos-por-unidade), [Importar lançamentos de uma planilha](artigo:lancamentos-planilha) e [Importar frações ideais de uma planilha](artigo:fracoes-ideais-planilha).
