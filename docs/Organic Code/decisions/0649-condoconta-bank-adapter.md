# 0649 — CondoConta como banco de cobrança, só no sandbox

- Status: Aceito (só sandbox da CondoConta; código do banco PENDENTE)
- Data: 23/11/2026
- Pedido: Djalma, 21/11/2026 (trocar a Cora pela CondoConta) e 23/11/2026 ("https://api-open.condoconta.com.br/
  vamos deixar o sicoob para depois").
- Relacionadas: ADR 0035 (adaptadores), 0156 (Inter), 0158 (credencial pela tela), 0318 (extrato pela API),
  0322 (Cora, cancelada), 0633 (Sicoob e CondoConta pendente)

## Contexto

A CondoConta publica a "CondoConta Open API" (OpenAPI 2.37.0, lida em 23/11/2026 pelo navegador do Djalma, porque o
proxy da nuvem bloqueia o host). CONFIRMADO na especificação:

- Sandbox `https://api-open-sandbox.condoconta.net`; produção `https://api-open.condoconta.com.br`.
- OAuth2 client credentials: `POST /token` (form) com `client_id`, `client_secret` e `scope`. Token de 1 hora; no
  máximo 5 pedidos de token por minuto. Sem certificado.
- Cobrança: `POST /slip` (síncrono, devolve `our_number`, linha digitável, código de barras, `pdf` como URL e
  `qr_code` do Pix; idempotente por `your_number` + valor), `GET /slip/{our_number}` (status CREATED, REGISTERED,
  PAID, CANCELED, ERROR; `received_value`, `payment_date`), `POST /slip/{our_number}/cancel` (204).
- Conta: `GET /account/balance` (saldo atual) e `GET /account/balance/sheet?initial_date&final_date` (saldo e
  transações por dia, `operation` CASH_IN/CASH_OUT, `transaction_code`).
- Webhooks `PUT /webhooks/{tipo}` com `callback_url`, um por tipo por client ID, reenvio por 60 minutos, sem
  assinatura documentada.
- Erros como `{detail: texto}` ou `{detail: [{loc, msg}]}`. Boleto de R$ 2,50 a R$ 250.000,00. Baixa automática de
  0 a 60 dias depois do vencimento.
- Credencial: pedida por e-mail a api@condoconta.com.br com o CNPJ do condomínio e o e-mail do responsável.
- Não há simulação de pagamento no sandbox.

PENDENTE: o código do banco (COMPE) que a conta CondoConta usa no cadastro da conta bancária. Não está na
especificação nem na lista pública de bancos consultada.

## Decisão

1. `CondoConta\CondoContaBankAdapter` (nome `condoconta`) implementa `BankAdapter`, `BankChargeExtras`,
   `BankStatementSource` e `BankPaymentSimulator` (só para o teste da credencial; `simulatePayment` recusa com
   mensagem). Só aceita a URL do sandbox; produção só com homologação e aprovação explícita do Djalma.
2. Emissão `type = CONDOMINIUM`, `your_number` = nosso número da Connect (até 16 números), valor em reais como
   texto, multa percentual e juros mensais percentuais a partir do dia seguinte ao vencimento, baixa automática em
   60 dias. `external_id` = `our_number` da CondoConta. A resposta com outro `your_number` é recusada.
3. Mapeamento: CREATED/REGISTERED = registrado (sem linha digitável = aguardando); PAID = baixa com
   `received_value` (ou `value`) e `payment_date` (ou o último `payments[].paid_at`); CANCELED = cancelado;
   ERROR = recusado; desconhecido = aguardando com aviso.
4. Token guardado na instância até 5 minutos antes de vencer; 401 fora de hora pede outro token uma vez; 429, 503 e
   504 esperam 3, 8 e 15 segundos (a emissão é idempotente pelo `your_number`).
5. PDF pela URL do campo `pdf`, só se o endereço for HTTPS em `condoconta.com.br` ou `condoconta.net` (o token não
   vai para outro host). Extrato pela folha diária; FITID = `condoconta-` + `transaction_code` (hash dos campos se
   faltar), com sufixo para repetidos. Saldo de hoje pelo saldo atual; dia passado pelo saldo do dia na folha.
6. Credencial pela mesma tela Integrações e pelo terminal (`--banco=condoconta`): client ID e client secret, sem
   certificado e sem número do cliente. Cofre, limite de tentativas e auditoria iguais aos outros bancos.
7. Webhook: o endereço sai da tela como nos outros bancos; o aviso só aponta quais cobranças consultar (sem
   assinatura, nada muda sem consultar a cobrança). Cadastro do webhook na CondoConta fica para a homologação.
8. Código do banco provisório `999` em `CondoContaBankAdapter::BANK_CODE`, só para o sandbox e os testes. Trocar
   pelo código que o Djalma confirmar no app da CondoConta; é a única linha a mudar.

## Consequências

- Checklist de homologação em `docs/banking/CONDOCONTA.md`.
- Nenhuma migration: `adapter` é texto livre e as colunas do certificado já aceitam vazio.
- Enquanto o código do banco não for confirmado, só uma conta cadastrada com o código provisório aparece no card.
- Sicoob continua como está (ADR 0633), deixado para depois a pedido do Djalma.
