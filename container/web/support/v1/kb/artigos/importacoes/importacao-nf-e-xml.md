---
codigo: AP-141
titulo: Importar nota fiscal eletrônica (NF-e XML)
resumo: Importe o XML da nota fiscal de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida.
categoria: importacoes
rota: /importacoes/importacao-nf-e-xml
menu: Importações > Importação NF-e (xml)
icone: cloud-upload-outline
situacao: funciona
relacionados: fornecedores, contas-a-pagar, extrato-bancario-ofx, inadimplentes-planilha
palavras: importação nf-e (xml), importações, AP-141
rascunho: nao
---

Importe o XML da nota fiscal de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida.

## Onde fica

No menu lateral, abra [[Importações > Importação NF-e (xml)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela importacao-nf-e-xml
rota: /importacoes/importacao-nf-e-xml
condominio: residencial-jardim-das-acacias
legenda: Tela Importação NF-e (xml)
1: menu | Importação NF-e (xml) no menu lateral
2: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

XML, até 1 MB. Nada é gravado antes de você conferir a prévia e aplicar.

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Enviar e ver a prévia}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela importacao-nf-e-xml-form
rota: /importacoes/importacao-nf-e-xml
condominio: residencial-jardim-das-acacias
legenda: Enviar arquivo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="chart_account_id"] | Conta de despesa (obrigatório)
2: main form:not([method=get]) [name="due_on"] | Vencimento (só para nota sem duplicata)
3: main form:not([method=get]) [name="file"] | Arquivo (obrigatório)
4: main form:not([method=get]) button:has-text("Enviar e ver a prévia") | Botão {{Enviar e ver a prévia}}
```

### O que preencher

- **Conta de despesa** (obrigatório): As contas a pagar das duplicatas entram nesta conta.
- **Vencimento (só para nota sem duplicata)**: Com duplicatas no XML, vale o vencimento de cada uma.
- **Arquivo** (obrigatório): O XML da NF-e (modelo 55) que o fornecedor enviou. Escolha o arquivo no seu computador.

## O que a lista mostra

Colunas: **Linha**, **Situação**, **Conteúdo** e **Erros**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Importações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar fornecedores](artigo:fornecedores), [Contas a pagar](artigo:contas-a-pagar) e [Conciliar o extrato bancário (OFX)](artigo:extrato-bancario-ofx).
