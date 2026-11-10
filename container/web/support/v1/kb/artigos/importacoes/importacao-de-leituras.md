---
codigo: AP-044
titulo: Importar leituras de consumo
resumo: Importa de uma planilha as leituras de água, gás ou energia por relógio e competência. Leitura já lançada na mesma competência é substituída, se ainda não foi cobrada.
categoria: importacoes
rota: /importacoes/importacao-de-leituras
menu: Importações > Importação de Leituras
icone: cloud-upload-outline
situacao: funciona
relacionados: extrato-bancario-ofx, importacao-nf-e-xml, inadimplentes-planilha, fracoes-ideais-planilha
palavras: importação de leituras, importações, AP-044
rascunho: nao
---

Importa de uma planilha as leituras de água, gás ou energia por relógio e competência. Leitura já lançada na mesma competência é substituída, se ainda não foi cobrada.

## Onde fica

No menu lateral, abra [[Importações > Importação de Leituras]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela importacao-de-leituras
rota: /importacoes/importacao-de-leituras
condominio: residencial-jardim-das-acacias
legenda: Tela Importação de Leituras
1: menu | Importação de Leituras no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela importacao-de-leituras-form
rota: /importacoes/importacao-de-leituras
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

>> Veja também: [Conciliar o extrato bancário (OFX)](artigo:extrato-bancario-ofx), [Importar nota fiscal eletrônica (NF-e XML)](artigo:importacao-nf-e-xml) e [Importar inadimplentes de uma planilha](artigo:inadimplentes-planilha).
