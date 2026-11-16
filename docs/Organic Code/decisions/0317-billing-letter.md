# 0317 — Carta de cobrança impressa (parte externa e interna) gerada pelo ERP

- Status: Aceito (etapa 1: layout e geração; etapa 2: tela de avisos pendente)
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("queria que você melhorasse o layout da parte interna e da externa [...] o fundo do
  bloco 10 [...] floral [...] cinza [...] na parte externa pode ser o mesmo conteúdo do externa PDF [...] uma área
  na parte de baixo [...] aviso, imagem, produto, propaganda [...] layout direitinho, paginado"), com três modelos:
  externa.pdf (envelope), interno.pdf (demonstrativo e boleto do sistema antigo, com dados fictícios) e
  bloco 10.pdf (gráfica, fundo floral cinza).
- Relacionadas: ADR 0156 (boletos do Inter), 0311 (pagador do boleto), 0314 (prestação de contas no padrão do PDF de outubro)

## Contexto

O ERP só tinha o PDF do boleto devolvido pelo Inter (ficha simples). A carta enviada pelo Correio (folha dobrada
em três com destinatário, demonstrativo e boleto) era feita fora do sistema, com alinhamento torto e dados
digitados à mão.

## Decisão

1. `BillingLetter` monta a carta de **um boleto registrado ou pago** (com linha digitável), só com dados do
   sistema: boleto (`erp_bank_charges`), conta a receber e fatura de origem, pagador (a mesma regra do registro
   no banco, `BankCharges::payerFor`), endereço do condomínio com bloco e apartamento, regras de cobrança (multa e
   juros nas instruções), demonstrativo do **mês anterior** à competência (o mesmo `AccountabilityDocument` da
   prestação de contas), contas bancárias e inadimplência no fim desse mês (ou hoje, se o mês não acabou).
2. Página 1, **parte externa**: fundo floral cinza da Connect em todo o papel (o motivo do floral dourado da
   Central de Ajuda em cinza, `assets/images/billing/floral-cinza.png`), aba de cima de cabeça para baixo com os
   canais de atendimento e o aplicativo (QR code do app do morador), logo e QR, destinatário na altura da janela,
   quadro "Para uso do Correio" e remetente (dados da Connect de `ListDocument::issuer()`). Marcas de dobra a 99 e
   198 mm.
3. Página 2, **parte interna**: cabeçalho (nº da conta, mês de referência, período, emissão, vencimento e valor),
   composição do boleto (item, total do condomínio e parte da unidade), demonstrativo em duas colunas, contas
   bancárias, inadimplência, **área de avisos** (caixa de 190 × 19 mm acima do picote) e ficha do boleto com
   linha digitável formatada e código de barras ITF-25 desenhado pelo próprio ERP (SVG).
4. **Paginação**: `BillingLetter::detailLevel` conta as linhas e escolhe quanto do demonstrativo cabe na parte
   interna (cada pagamento → total por conta → só grupos → só totais). Quando não cabe inteiro, o detalhe
   completo vai numa folha de continuação. A ficha do boleto fica sempre no mesmo lugar, no pé da página 2.
5. API `GET /api/v1/bank-charges/{id}/letter` (mesma permissão do PDF do boleto, `erp.bank_charges.view`, escopo
   pelo condomínio, auditada como leitura) e botão **Carta** na lista de boletos.
6. Os canais da aba externa ficam como constante (`BillingLetter::CHANNELS`), com os números do externa.pdf.
   Na etapa 2 a tela de avisos passa a guardar os avisos/propaganda e esses canais.

## Consequências

- O dompdf gira elementos com padding/margem em volta do ponto errado; o bloco girado não tem padding (o espaço
  interno fica num filho). Bloco com posição absoluta: a largura não inclui o padding.
- Boleto do sandbox interno não tem código de barras no padrão Febraban: a carta sai sem as barras.
- Descrições longas do demonstrativo são cortadas com reticências na parte interna; a folha de continuação mostra
  o mesmo corte (a prestação de contas continua com o texto inteiro).

## Testes

`BillingLetterTest` (linha digitável, ITF-25, instruções, níveis do demonstrativo, nomes e documentos).
