# CondoConta — integração de cobrança (ADR 0649, só sandbox)

Situação em 23/11/2026: adaptador `CondoConta\CondoContaBankAdapter` pronto e testado com um banco falso. Falta a
credencial do sandbox e o código do banco da conta CondoConta.

## O que o Djalma precisa fazer

1. **Credencial de teste.** E-mail para api@condoconta.com.br com o CNPJ de um condomínio e o e-mail de quem cuida
   da integração, pedindo **client ID e client secret do sandbox** da CondoConta Open API.
2. **Código do banco.** No app da CondoConta, em dados da conta, conferir o número do banco. Ele vai em
   `CondoContaBankAdapter::BANK_CODE` (hoje o provisório `999`) e no cadastro da conta bancária no ERP.

Nunca mandar client secret pelo chat: ele entra só pela tela Integrações ou pelo terminal.

## Contrato (CONFIRMADO na OpenAPI 2.37.0)

| Uso | Chamada |
| --- | --- |
| Token | `POST /token` (form: grant_type=client_credentials, scope, client_id, client_secret) |
| Emitir boleto com Pix | `POST /slip` |
| Consultar | `GET /slip/{our_number}` |
| Cancelar | `POST /slip/{our_number}/cancel` |
| Saldo atual | `GET /account/balance` |
| Extrato por dia | `GET /account/balance/sheet?initial_date&final_date` |
| Webhook | `PUT /webhooks/slip` com `callback_url` |

Escopos pedidos: `boleto-cobranca.write boleto-cobranca.read extrato.read webhook.write webhook.read`.

## Checklist da homologação no sandbox

- [ ] Gravar a credencial pela tela Integrações (teste do token passa).
- [ ] Emitir um boleto e conferir linha digitável, código de barras, PDF e Pix copia e cola.
- [ ] Emitir de novo o mesmo boleto e conferir que volta a mesma cobrança (idempotência pelo your_number).
- [ ] Consultar: conferir os nomes reais dos campos `received_value`, `payment_date` e `status_date` (INFERIDO no
      mapeamento de pago e cancelado).
- [ ] Pagar no sandbox, se a CondoConta tiver como, e ver a baixa pela consulta de hora em hora.
- [ ] Cancelar um boleto aberto (204) e um pago (deve recusar).
- [ ] Buscar extrato e saldo; conferir se as transações da folha trazem data própria e `transaction_code`.
- [ ] Conferir se o PDF exige o token ou é URL pública, e o domínio do arquivo.
- [ ] Cadastrar o webhook `slip` com o endereço mostrado pela tela e ver um aviso chegar.
- [ ] Confirmar o código do banco e trocar o provisório.

Produção (`api-open.condoconta.com.br`) só depois desta lista e com aprovação explícita do Djalma.
