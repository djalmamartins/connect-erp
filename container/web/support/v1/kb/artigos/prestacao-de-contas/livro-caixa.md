---
codigo: CN-007
titulo: Livro caixa mensal
resumo: Tudo o que entrou e saiu do caixa do condomínio no mês, com o saldo dia a dia e as notas fiscais e comprovantes anexados aos lançamentos. Sai pronto em PDF, ou num pacote com os anexos.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/livro-caixa
menu: Prestação de contas > Livro Caixa
icone: document-text-outline
situacao: funciona
relacionados: lancamentos, extrato-conta, prestacao-de-contas, fechamento-mensal
palavras: livro caixa, caixa, prestação de contas, nota fiscal, comprovante, recibo, anexo, PDF, síndico, conselho fiscal, CN-007
rascunho: nao
---

## Para que serve

O livro caixa mostra, em ordem de data, cada pagamento e recebimento do condomínio no mês, com o saldo depois de cada movimento. Ele junta as notas fiscais e os comprovantes que foram anexados aos lançamentos: cada anexo recebe um número (**A1**, **A2**...) que aparece na linha do lançamento. O livro sai pronto para o síndico e o conselho fiscal, com o resumo do mês, as contas bancárias, o resumo por conta e o espaço das assinaturas.

## Onde fica

No menu lateral, abra [[Prestação de contas > Livro Caixa]]. O livro é de um condomínio só: escolha o condomínio na barra superior.

```tela livro-caixa
rota: /prestacao-de-contas/livro-caixa
condominio: residencial-jardim-das-acacias
legenda: Livro caixa do mês
1: main form[method=get] | Escolha do mês
2: main .erp-page-head__actions :is(a,button):has-text("Baixar PDF") | Botão {{Baixar PDF}}
3: main .erp-page-head__actions :is(a,button):has-text("Pacote com anexos") | Botão {{Pacote com anexos}}
```

## Como gerar o livro do mês

1. Confira o condomínio na barra superior.
2. A tela abre no mês anterior (o que costuma ser entregue). Para outro, escolha o **Mês** e clique em {{Gerar}}. A tela mostra o saldo anterior, as entradas, as saídas, o saldo final e cada movimento.
3. Clique em {{Baixar PDF}} para o livro pronto, ou em {{Pacote com anexos}} para um arquivo ZIP com o PDF e todos os anexos numerados (pasta `anexos`).
4. Para imprimir direto do navegador, use {{Imprimir}}.

> As fotos (JPG, PNG, WebP) entram dentro do PDF, uma por página, no fim do livro. Anexos em PDF ou XML não cabem dentro de outro PDF: o índice dos anexos diz que estão no pacote, com o nome do arquivo.

## O que entra no livro

- Só lançamentos **válidos** com **Pago em** dentro do mês (regime de caixa). Conta em aberto não entra.
- O **saldo anterior** é a soma dos saldos das contas bancárias no dia 1, com o saldo inicial de cada conta.
- Transferências entre contas do próprio condomínio não mudam o caixa: aparecem só no quadro das contas bancárias.
- No alto, o livro confere o saldo final com a soma das contas bancárias. Se houver diferença, ela aparece em vermelho (em geral, um lançamento pago sem conta bancária).

## Notas fiscais e comprovantes

Os anexos ficam no próprio lançamento. Abra o lançamento em [[Financeiro > Lançamentos > Lançamentos]] e use o quadro **Notas e comprovantes**, ao lado do formulário. Veja o passo a passo em [Lançamentos](artigo:lancamentos).

O livro lista, em **Documentos das despesas**, as despesas pagas sem nota fiscal, comprovante ou recibo. Na tela, essas linhas mostram **sem comprovante**: clique para abrir o lançamento e anexar.

>! Enquanto o mês não for fechado no [Fechamento mensal](artigo:fechamento-mensal), o livro sai marcado como **Prévia**. Feche o mês antes de entregar a versão final.

## Quem pode usar

Quem tem acesso aos **Lançamentos** do condomínio pode ver e baixar o livro. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e cada download fica registrado na auditoria.
