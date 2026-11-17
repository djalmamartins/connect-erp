# Banco Inter — integração de cobrança (ADR 0156)

Situação: **só sandbox do Inter**. Produção depende de homologação e aprovação explícita do Djalma.

## O que está pronto

| Capacidade | Como |
| --- | --- |
| Autenticação | OAuth2 client_credentials + mTLS (certificado e chave em memória, nunca em disco) |
| Emitir boleto com Pix | `POST /api/v1/bank-charges` (tela Boletos), pagador = responsável financeiro ou proprietário |
| Consultar | `POST /api/v1/bank-charges/{id}/refresh` |
| Cancelar | `POST /api/v1/bank-charges/{id}/cancel` |
| PDF | `GET /api/v1/bank-charges/{id}/pdf` |
| Baixa | webhook + consulta no banco, conciliação do ADR 0035 |
| Remessas | `GET /api/v1/bank-remittances` (lotes por conta e dia; sem arquivo) |
| Extrato e saldo | `POST /api/v1/imports/bank-statement` (Importações > Extrato, mesma prévia do OFX; escopo `extrato.read` em token separado, ADR 0318) |

## Sandbox: de onde vem a credencial

- Crie a aplicação no portal de sandbox (https://developers.inter.co/sandbox). Não use o Internet Banking: a integração criada lá
  tem certificado de **produção**, emitido pela "API Intermediate Certificate Authority", e o host de sandbox
  recusa esse certificado no handshake com `tlsv1 alert unknown ca` (CONFIRMADO em 11/11/2026).
- O sandbox só fica disponível de segunda a sexta, das 8h às 20h (horário de Brasília), segundo o aviso do portal. Fora desse horário, o `testar` falha.
- Guarde o certificado de produção fora da pasta do projeto até a aprovação de produção.

## Passo a passo pela tela (ADR 0158)

1. `CONNECT_BANK_VAULT_KEY` no `.env` (uma vez por ambiente, `php tools/bank-credentials.php gerar-chave`).
2. Conta bancária do condomínio com banco `077`.
3. Configurações > Integrações > card **Banco Inter**: escolher a conta, client ID, secret e o ZIP da aplicação.
   O sistema testa no sandbox antes de gravar e mostra o endereço do webhook uma única vez.

## Passo a passo (servidor)

1. `php tools/bank-credentials.php gerar-chave` → colar `CONNECT_BANK_VAULT_KEY=...` no `.env` (fora do Git).
2. Conta bancária do condomínio com banco `077`.
3. `php tools/bank-credentials.php cadastrar --conta=ID --banco=inter --client-id=... --certificado=Inter.crt --chave=Inter.key`
4. `php tools/bank-credentials.php testar --conta=ID` (só token, não emite nada).
5. Cadastrar no Inter o endereço do webhook mostrado no passo 3.
6. Proxy (nginx) na rota `/api/v1/webhooks/bank/`: exigir certificado de cliente do Inter (`ssl_verify_client`)
   e liberar só os IPs informados pelo banco. Limite de corpo 256 KB. Na hospedagem sem proxy próprio, os IPs vão em
   `BANK_WEBHOOK_ALLOWED_IPS` no `.env` (ADR 0360).

## Homologação no sandbox (checklist)

- [x] Token obtido (`testar` e tela Integrações).
- [ ] Emitir boleto com pagador PJ (PF conferido em 16/11/2026).
- [ ] Conferir multa/mora no PDF do Inter (o registro com esses campos foi aceito em 16/11/2026).
- [x] Token obtido com a aplicação do portal de sandbox (16/11/2026).
- [x] Boleto registrado no sandbox com linha digitável, Pix e PDF (16/11/2026); pagador sem CPF recusado antes do banco.
- [x] Simular pagamento (16/11/2026): botão **Simular pagamento** em Cobrança > Boletos chama
  `POST /cobranca/v3/cobrancas/{codigoSolicitacao}/pagar` com `pagarCom` (CONFIRMADO; o `Accept` precisa incluir
  `application/problem+json`, senão 406) e consulta: boleto **Pago** e conta a receber baixada.
- [x] Consulta automática (decisão do Djalma, 16/11/2026): `tools/bank-charges-sync.php`, de hora em hora pelo
  `service/jobs/scheduler.sh`, consulta os boletos em aberto dos bancos reais e dá baixa sem webhook.
- [ ] Ver a baixa chegar pelo webhook (precisa de endereço público; fica para o servidor com domínio).
- [x] Cancelar boleto (16/11/2026): boleto **Cancelado** e conta a receber continua em aberto.
- [ ] Erro de validação (CPF inválido, vencimento passado) aparece como Recusado com a mensagem do banco.
- [ ] Reenvio do mesmo webhook não duplica; webhook com segredo errado = 404.
- [ ] Extrato e saldo (ADR 0318; 429 por limite de consultas visto em 16/11/2026: o adaptador espera 5, 15 e 30 s e repete; saldo que não vem não impede o extrato; extrato completo com 500 no sandbox em 16/11/2026 cai para o extrato simples): a aplicação do sandbox precisa da permissão de extrato; buscar um período em
  Importações > Extrato, conferir linhas, FITID e saldo e aplicar.

Itens INFERIDOS do contrato estão listados no ADR 0156.
