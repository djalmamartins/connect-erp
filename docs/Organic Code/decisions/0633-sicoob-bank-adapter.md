# 0633 — Sicoob como banco de cobrança, só no sandbox; CondoConta aguardando a documentação da API

- Status: Aceito (só sandbox do Sicoob)
- Data: 21/11/2026
- Pedido: Djalma, 21/11/2026: cancelou a Cora (Cora Pro é pago por mês, ADR 0322) e pediu a integração com a
  CondoConta, "e sicoob".
- Relacionadas: ADR 0035 (adaptadores), 0156 (Inter), 0158 (credencial pela tela), 0318 (extrato pela API), 0322 (Cora)

## Contexto

**CondoConta.** Tem uma "CondoConta Open API" (api-open.condoconta.com.br), mas a especificação só abre num
navegador com JavaScript e o acesso é pelo programa de parceiros (gratuito, sem formulário técnico público). O site
diz que no modelo deles (Receita Automática) a própria CondoConta emite os boletos e devolve os pagamentos ao ERP
da administradora, com integração por API, CNAB ou VAN. Não há base URL, autenticação, endpoints nem sandbox
documentados em público. Codificar um adaptador agora seria inventar contrato. PENDENTE: o Djalma pedir à CondoConta
a especificação OpenAPI, um acesso de teste e o cadastro da Connect como parceira.

**Sicoob.** A API Cobrança Bancária v3 e a Conta Corrente são públicas, com sandbox no portal
developers.sicoob.com.br (client ID e access token fixos, sem certificado; as respostas do sandbox são fixas).
Em produção o token sai de auth.sicoob.com.br com `client_credentials`, só client ID + mTLS do certificado A1.
O boleto é identificado por `numeroCliente` (código do beneficiário) + `codigoModalidade` + `nossoNumero`
(número que o Sicoob devolve). Valores em reais com casas decimais. O sandbox não simula pagamento.

## Decisão

1. `Sicoob\SicoobBankAdapter` (nome `sicoob`, banco `756`) implementa `BankAdapter`, `BankChargeExtras`,
   `BankStatementSource` e `BankPaymentSimulator` (só para o teste da credencial; `simulatePayment` recusa com
   mensagem). Só aceita a URL do sandbox; produção só com homologação e aprovação explícita do Djalma.
2. Emissão `POST /cobranca-bancaria/v3/boletos` com Pix (`codigoCadastrarPIX = 1`), emissão e entrega pela Connect
   (`identificacaoEmissaoBoleto = 2`, `identificacaoDistribuicaoBoleto = 2`, `gerarPdf = false`), multa percentual
   e juros como taxa mensal (INFERIDO), a partir do dia seguinte ao vencimento. `seuNumero` = nosso número da Connect;
   `external_id` = `nossoNumero` do Sicoob.
3. Consulta, baixa (`POST .../boletos/{nossoNumero}/baixar`), segunda via em PDF (`pdfBoleto` em base64), extrato por
   mês (`/conta-corrente/v3/extrato/{mes}/{ano}`) e saldo (`/conta-corrente/v2/saldo`). FITID do extrato pelo hash
   dos campos (o Sicoob não manda id da transação), com sufixo para linhas iguais.
4. Mapeamento de `situacaoBoleto`: Em Aberto/Vencido = registrado; Liquidado = baixa (valor e data do último
   histórico, INFERIDO); Baixado = cancelado; desconhecido = aguardando com aviso. No sandbox o `seuNumero` devolvido
   não é conferido (o sandbox devolve sempre o boleto de exemplo).
5. Credencial pela mesma tela (card "Banco Inter, Cora e Sicoob"): para conta 756 o campo de secret vira
   "Access token do sandbox", aparece "Número do cliente" e o certificado fica opcional. Cofre, limite de tentativas
   e auditoria iguais. O terminal aceita `--banco=sicoob --numero-cliente=...` sem certificado.
6. `CurlInterHttp` só manda certificado quando há um (o sandbox do Sicoob não usa mTLS). Inter e Cora continuam
   sempre com certificado.
7. Modalidade de cobrança sai da carteira da conta bancária (só números), padrão 1 (simples com registro).
8. Webhook do Sicoob PENDENTE (sem endereço público); a baixa vem da consulta de hora em hora.

## Consequências

- Contrato INFERIDO de fontes públicas (o proxy da nuvem não abre developers.sicoob.com.br): checklist em
  `docs/banking/SICOOB.md`. Como o sandbox devolve dados fixos, a homologação de verdade é no ambiente de
  produção controlado, com aprovação do Djalma.
- O saldo do Sicoob é só o de agora: extrato de período passado sai sem saldo final.
- Nenhuma migration: `adapter` é texto livre e as colunas do certificado já aceitam vazio.
- CondoConta não entra no código até chegar a documentação; quando chegar, novo ADR.
