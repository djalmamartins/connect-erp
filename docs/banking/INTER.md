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
   e liberar só os IPs informados pelo banco. Limite de corpo 256 KB.

## Homologação no sandbox (checklist)

- [ ] Token obtido (`testar`).
- [ ] Emitir boleto com pagador PF e PJ; conferir linha digitável, Pix e PDF.
- [ ] Conferir os nomes de campo do contrato inferido (multa/mora, `numDiasAgenda`, `mensagem`).
- [ ] Simular pagamento no sandbox e ver a baixa chegar pelo webhook.
- [ ] Cancelar boleto; conferir `CANCELADO` e o motivo.
- [ ] Erro de validação (CPF inválido, vencimento passado) aparece como Recusado com a mensagem do banco.
- [ ] Reenvio do mesmo webhook não duplica; webhook com segredo errado = 404.

Itens INFERIDOS do contrato estão listados no ADR 0156.
