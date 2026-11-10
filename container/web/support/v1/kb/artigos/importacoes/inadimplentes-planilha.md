---
codigo: AP-142
titulo: Importar inadimplentes de uma planilha
resumo: Carga do histórico de débitos quando o condomínio chega à Connect: as receitas em aberto das unidades, com vencimento no passado.
categoria: importacoes
rota: /importacoes/inadimplentes-planilha
menu: Importações > Inadimplentes (Planilha)
icone: cloud-upload-outline
situacao: funciona
relacionados: importacao-nf-e-xml, fracoes-ideais-planilha, extrato-bancario-ofx, lancamentos-planilha
palavras: inadimplentes (planilha), importações, AP-142
rascunho: nao
---

Carga do histórico de débitos quando o condomínio chega à Connect: as receitas em aberto das unidades, com vencimento no passado.

## Onde fica

No menu lateral, abra [[Importações > Inadimplentes (Planilha)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-planilha
rota: /importacoes/inadimplentes-planilha
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Planilha)
1: menu | Inadimplentes (Planilha) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

CSV OU XLSX, até 2 MB e 5.000 linhas. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela inadimplentes-planilha-form
rota: /importacoes/inadimplentes-planilha
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

>> Veja também: [Importar nota fiscal eletrônica (NF-e XML)](artigo:importacao-nf-e-xml), [Importar frações ideais de uma planilha](artigo:fracoes-ideais-planilha) e [Conciliar o extrato bancário (OFX)](artigo:extrato-bancario-ofx).
