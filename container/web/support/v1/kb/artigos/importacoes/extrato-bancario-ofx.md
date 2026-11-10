---
codigo: AP-140
titulo: Conciliar o extrato bancário (OFX)
resumo: Importe o extrato da conta bancária em OFX (o arquivo que o internet banking exporta). Cada linha do extrato procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O que não bater fica pendente para você conferir.
categoria: importacoes
rota: /importacoes/extrato-bancario-ofx
menu: Importações > Extrato Bancário (OFX)
icone: cloud-upload-outline
situacao: funciona
relacionados: importacao-de-leituras, importacao-nf-e-xml, inadimplentes-planilha, fracoes-ideais-planilha
palavras: extrato bancário (ofx), importações, AP-140, conciliação, ofx, extrato, internet banking
rascunho: nao
---

Importe o extrato da conta bancária em OFX (o arquivo que o internet banking exporta). Cada linha do extrato procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O que não bater fica pendente para você conferir.

## Onde fica

No menu lateral, abra [[Importações > Extrato Bancário (OFX)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela extrato-bancario-ofx
rota: /importacoes/extrato-bancario-ofx
condominio: residencial-jardim-das-acacias
legenda: Tela Extrato Bancário (OFX)
1: menu | Extrato Bancário (OFX) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

OFX, até 2 MB. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela extrato-bancario-ofx-form
rota: /importacoes/extrato-bancario-ofx
condominio: residencial-jardim-das-acacias
legenda: Enviar arquivo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="bank_account_id"] | Conta bancária do extrato (obrigatório)
2: main form:not([method=get]) [name="file"] | Arquivo (obrigatório)
3: main form:not([method=get]) button:has-text("Enviar e ver a prévia") | Botão {{Enviar e ver a prévia}}
```

### O que preencher

- **Conta bancária do extrato** (obrigatório).
- **Arquivo** (obrigatório): O número da conta no OFX precisa ser o da conta escolhida. Escolha o arquivo no seu computador.

## O que a lista mostra

Colunas: **Linha**, **Situação**, **Conteúdo** e **Erros**.

> O extrato não cria lançamentos: ele só confere. Lance o que faltar e importe de novo.

> Na tela: *As linhas que o extrato não conseguir conferir sozinho aparecem aqui.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Importações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Importar leituras de consumo](artigo:importacao-de-leituras), [Importar nota fiscal eletrônica (NF-e XML)](artigo:importacao-nf-e-xml) e [Importar inadimplentes de uma planilha](artigo:inadimplentes-planilha).
