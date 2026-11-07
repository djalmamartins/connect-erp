# Integrações bancárias — requisitos essenciais do Connect ERP
A auditoria de referência menciona boletos, remessas, retornos, pagamentos bancários, contas bancárias, conciliação e homologação por instituição; **não prova quais APIs, bancos ou layouts estão suportados**. Estes itens são requisitos NOVOS/AMPLIADOS do proprietário e exigem validação por instituição.

## Capacidades
1. Configurar instituição, agência, conta, convênio, carteira, ambiente, certificado e credenciais (vault, nunca em texto aberto).
2. Emitir e registrar boleto via API bancária ou arquivo CNAB conforme contrato do banco; armazenar identificadores, nosso número, linha digitável, código de barras, PDF e status.
3. Consultar títulos; registrar confirmação, liquidação, rejeição, vencimento, protesto quando contratado, alteração e cancelamento/baixa.
4. Importar remessa/retorno CNAB 240/400 somente nos layouts homologados; rejeitar layouts desconhecidos; validar trailer, totais e encoding.
5. Receber webhook autenticado, validar assinatura/origem quando aplicável, guardar evento bruto protegido e processar idempotentemente.
6. Conciliar retorno/API/OFX com fatura e recebimento; divergências em fila de revisão, nunca baixa silenciosa.
7. Pix cobrança/QR dinâmico e Pix associado ao boleto somente onde houver contrato e suporte do banco.
8. Tratar tarifas, juros, multa, desconto, abatimento, pagamento parcial e múltiplas ocorrências conforme regras aprovadas.
9. Reconciliação diária, retentativas com backoff, dead-letter, outbox, métricas e alertas sem vazamento de dados.
10. Sandbox, homologação formal e aprovação antes de ativar credenciais/ambiente de produção.

## Interface de adaptadores
BankAdapter: capabilities(), validateConfiguration(), registerCharge(), getCharge(), cancelCharge(), downloadBoleto(), parseReturn(), createRemittance(), verifyWebhook(). Cada banco implementa apenas capacidades comprovadas. Não inventar endpoints, padrões CNAB, carteiras ou autenticação.

## Critérios de aceite
- Mesmo pedido/evento repetido não cria título ou recebimento duplicado.
- Um boleto só muda para 'registrado' com confirmação bancária; erro/timeout gera estado pendente verificável.
- Baixa é rastreável a evidência bancária e conciliável ao razão.
- Divergências e liquidações parciais não são descartadas.
- Testes com fixtures sintéticas: sucesso, rejeição, duplicidade, timeout, webhook fora de ordem, retorno malformado, estorno, cancelamento e concorrência.
- Nenhum teste envia cobranças reais.

## Decisões pendentes
Bancos prioritários; pessoa jurídica titular da cobrança; modalidade de cobrança, convênio e carteira; API vs CNAB; requisitos Pix; credenciais sandbox; regras de juros/multa e política de cobrança.
