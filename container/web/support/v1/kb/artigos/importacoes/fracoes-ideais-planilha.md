---
codigo: AP-143
titulo: Importar frações ideais de uma planilha
resumo: Importa a fração ideal de cada unidade. A planilha precisa trazer todas as unidades ativas, sem repetir, e a soma tem de dar 1 (100%).
categoria: importacoes
rota: /importacoes/fracoes-ideais-planilha
menu: Importações > Frações Ideais (Planilha)
icone: cloud-upload-outline
situacao: funciona
relacionados: fracao-ideal, inadimplentes-planilha, lancamentos-planilha, importacao-nf-e-xml
palavras: frações ideais (planilha), importações, AP-143
rascunho: nao
---

Importa a fração ideal de cada unidade. A planilha precisa trazer todas as unidades ativas, sem repetir, e a soma tem de dar 1 (100%).

## Onde fica

No menu lateral, abra [[Importações > Frações Ideais (Planilha)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela fracoes-ideais-planilha
rota: /importacoes/fracoes-ideais-planilha
condominio: residencial-jardim-das-acacias
legenda: Tela Frações Ideais (Planilha)
1: menu | Frações Ideais (Planilha) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela fracoes-ideais-planilha-form
rota: /importacoes/fracoes-ideais-planilha
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

>> Veja também: [Preencher a fração ideal e a área das unidades](artigo:fracao-ideal), [Importar inadimplentes de uma planilha](artigo:inadimplentes-planilha) e [Importar lançamentos de uma planilha](artigo:lancamentos-planilha).
