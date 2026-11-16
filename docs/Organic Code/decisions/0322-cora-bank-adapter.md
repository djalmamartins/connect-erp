# 0322 — Cora (Integração Direta) como segundo banco de cobrança, só no ambiente de teste

- Status: Aceito (só stage da Cora)
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("vamos iniciar a integração com a cora"), seguindo o desenho do Inter.
- Relacionadas: ADR 0035 (adaptadores), 0156 (Inter), 0158 (credencial pela tela), 0318 (extrato pela API)

## Contexto

O Inter já emite, consulta, cancela, simula pagamento, dá baixa pela consulta de hora em hora e traz o extrato.
A Cora tem API própria (Integração Direta, plano Cora Pro) com boleto e Pix, extrato e saldo. Ela difere do Inter em:
autenticação só com client ID + certificado (sem client secret, token de 24 h), emissão síncrona com
`Idempotency-Key`, valores em centavos inteiros, cancelamento por `DELETE`, simulação de pagamento em
`POST /v2/invoices/pay`, valor mínimo de R$ 5,00 e host `matls-clients` com mTLS em todas as chamadas.

## Decisão

1. `Cora\CoraBankAdapter` (nome `cora`, banco `403`) implementa `BankAdapter`, `BankChargeExtras`,
   `BankStatementSource` e a nova `BankPaymentSimulator` (que o Inter também passa a implementar). Só aceita a URL
   de stage; produção só com homologação e aprovação explícita do Djalma.
2. `Idempotency-Key` estável (UUID derivado da conta e do nosso número): emitir de novo o mesmo título devolve o
   boleto já criado, sem cobrança em dobro. 429/503/504 esperam 3, 8 e 15 s e repetem.
3. Mapeamento: OPEN/LATE = registrado; IN_PAYMENT = registrado com aviso (sem baixa); PAID = baixa com
   `total_paid` e `occurrence_date`; CANCELLED = cancelado; DRAFT/INITIATED = aguardando; desconhecido = aguardando com aviso.
4. Credencial pela mesma tela (card "Banco Inter e Cora"): o banco sai do código da conta; para a Cora o campo
   de secret some. O cofre, o limite de tentativas e a auditoria são os mesmos.
5. Reaproveitado sem mudança: emissão unitária e em lote (Gerar boletos), consulta automática de hora em hora
   (`tools/bank-charges-sync.php`), Simular pagamento (só boleto; a Cora não distingue Pix na simulação),
   extrato em Importações (FITID `cora-` + id da movimentação), Pix na carta de cobrança e no app do morador.
6. Webhook da Cora fica PENDENTE (sem endereço público); `parseWebhook` recusa.

## Consequências

- Contrato INFERIDO da documentação pública (developers.cora.com.br, 16/11/2026); itens a conferir no stage em
  `docs/banking/CORA.md` (unidade do saldo, `interest.rate` mensal, domínio do PDF, resposta do cancelamento).
- O saldo da Cora é só o de agora: extrato de período passado sai sem saldo final no OFX.
- Nenhuma migration: `adapter` já é texto livre.
