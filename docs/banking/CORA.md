# Cora — integração de cobrança (ADR 0322)

Situação: **só o ambiente de teste (stage) da Cora**. Produção depende de homologação e aprovação explícita do Djalma.

## O que está pronto

| Capacidade | Como |
| --- | --- |
| Autenticação | `POST /token` com client ID + mTLS (certificado e chave em memória, nunca em disco); sem client secret |
| Emitir boleto com Pix | `POST /api/v1/bank-charges` (tela Boletos) e em lote pela fatura (Gerar boletos); `Idempotency-Key` estável |
| Consultar | `POST /api/v1/bank-charges/{id}/refresh` e a consulta automática de hora em hora (`tools/bank-charges-sync.php`) |
| Cancelar | `POST /api/v1/bank-charges/{id}/cancel` (`DELETE /v2/invoices/{id}` na Cora) |
| Simular pagamento | botão **Simular pagamento** (só stage; `POST /v2/invoices/pay`) |
| PDF | `GET /api/v1/bank-charges/{id}/pdf` (só se a Cora devolver o PDF num endereço `*.cora.com.br`) |
| Extrato e saldo | Importações > Extrato > **Buscar extrato do banco** (`GET /bank-statement/statement`, `GET /third-party/account/balance`) |
| Webhook | PENDENTE (precisa de endereço público) |

## Passo a passo para o Djalma (Cora Web, ambiente de teste)

Pré-requisito: a conta Cora precisa do plano **Cora Pro** (é o que libera a Integração Direta).

1. Entre na **Cora Web** (app.cora.com.br). A credencial só é gerada pela Web, não pelo app do celular.
2. Menu **Conta > Integrações via APIs > Integração Direta** e clique em **Solicitar integração**; aceite os
   Termos e Condições. O client ID é criado na hora.
3. Em **Integração Direta**, abra **Ambiente de teste > Dados da sua credencial e client-id**.
4. Clique em **Gerar uma nova credencial**. O certificado e a chave privada baixam sozinhos. Anote o **client ID**
   desta tela (é o do ambiente de teste; o de produção é outro). Atenção: pela Web só dá para gerar **2 credenciais
   de teste por ano**; a partir da terceira, só por e-mail com a Cora.
5. Ainda em **Ambiente de teste** ficam as duas **contas de teste** (principal = a empresa; secundária = um cliente
   de mentira) e, no app, o link do **aplicativo de teste**. Não precisa delas para começar.
6. No Connect ERP, em **Financeiro > Contas bancárias**, cadastre a conta do condomínio com o banco **403** (Cora).
7. Em **Configurações > Integrações**, card **Banco Inter e Cora**: escolha a conta da Cora, cole o client ID e
   envie o certificado e a chave juntos (ou o ZIP). Clique em **Testar e gravar**: o sistema só pede um token, não emite nada.
8. Nunca mande o certificado, a chave ou o client ID pelo chat.

## Homologação no stage (checklist)

- [ ] Token obtido (tela Integrações, botão Testar).
- [ ] Emitir boleto com pagador PF: linha digitável, código de barras e Pix (EMV) voltam na hora.
- [ ] Emitir com pagador PJ.
- [ ] Conferir no PDF da Cora a multa (2%) e os juros: confirmar que `interest.rate` é **taxa mensal** (INFERIDO).
- [ ] Reemitir o mesmo título (mesmo nosso número) devolve o mesmo boleto (Idempotency-Key).
- [ ] Simular pagamento: boleto **Pago** e conta a receber baixada (pela consulta). Se a Cora responder
      `REC-0007` (pagar boleto emitido pela própria conta), usar a conta de teste secundária e registrar aqui.
- [ ] Consulta automática de hora em hora dá a baixa sem clicar em nada.
- [ ] Cancelar boleto em aberto (resposta 204 ou 200) e tentar cancelar um pago (`REC-0006`, mensagem "já foi pago").
- [ ] Erro de validação (CPF inválido, vencimento passado, valor < R$ 5,00) aparece como Recusado com a mensagem da Cora.
- [ ] PDF: anotar em que domínio a Cora devolve `payment_options.bank_slip.url`; se não for `*.cora.com.br`, ajustar o adaptador.
- [ ] Extrato de um período com movimento: linhas, FITID `cora-...`, e se o saldo (`balance`) vem **em centavos** (INFERIDO).
- [ ] Carta de cobrança mostra o QR do Pix do boleto da Cora.
- [ ] Webhook (fica para o servidor com domínio).

## Diferenças em relação ao Inter

- Sem client secret; token de 24 h (o adaptador renova 5 minutos antes).
- Emissão síncrona (sem "em processamento") e com `Idempotency-Key` obrigatória.
- Valores em centavos inteiros; mínimo R$ 5,00 (Inter: R$ 2,50).
- Simulação de pagamento só tem uma forma (o botão "Simular Pix" não aparece para a Cora).
- O saldo é só o de agora: extrato de período passado sai sem saldo final.
- O host de stage (`matls-clients.api.stage.cora.com.br`) não é bloqueado por horário, segundo a documentação.

Itens INFERIDOS do contrato estão no ADR 0322 e no cabeçalho de `source/Services/Erp/Banking/Cora/CoraBankAdapter.php`.
