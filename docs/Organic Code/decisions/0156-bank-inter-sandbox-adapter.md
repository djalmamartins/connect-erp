# ADR 0156 — Banco Inter como primeiro adaptador bancário real (só sandbox)

- Situação: aceita (10/11/2026)
- Contexto: decisão do Djalma em 10/11/2026 ("Vamos iniciar com banco inter"), depois da comparação de bancos
  da issue #46. Requisitos em `docs/banking/BANKING_INTEGRATIONS.md`; base do ADR 0035 (boletos, retorno e
  conciliação com o adaptador sandbox).

## Contexto

CONFIRMADO no repositório: `BankAdapter` (ADR 0035) com `SandboxBankAdapter`; `BankCharges` sempre usava o
adaptador padrão; `BankReturns` concilia eventos com idempotência por `event_key`; `SecretBox` (AES-256-GCM) já
protege o segredo do 2FA; `source/Models/Banking/AppBankInter.php` é um cliente antigo, só de token, que lê
certificado de arquivo e credenciais do ambiente (não é usado pelo ERP e não foi alterado).

INFERIDO (documentação pública do Inter e SDKs de terceiros; a página de referência do Inter é dinâmica e não
pôde ser lida automaticamente): API de Cobrança v3 com OAuth2 `client_credentials` + mTLS em `/oauth/v2/token`
(escopos `boleto-cobranca.read boleto-cobranca.write`, token de 1 hora, limite de 5 tokens por minuto); emissão
assíncrona em `POST /cobranca/v3/cobrancas` que devolve `codigoSolicitacao`; consulta, PDF e cancelamento em
`/cobranca/v3/cobrancas/{codigoSolicitacao}[/pdf|/cancelar]`; situações `EM_PROCESSAMENTO`, `A_RECEBER`,
`ATRASADO`, `RECEBIDO`, `MARCADO_RECEBIDO`, `CANCELADO`, `EXPIRADO`, `FALHA_EMISSAO`, `PROTESTO`; webhook com
lista de cobranças; sandbox em `https://cdpj-sandbox.partners.uatinter.co`. Os códigos de multa/mora
(`PERCENTUAL`, `TAXAMENSAL`) e o valor mínimo de R$ 2,50 também são inferidos. **Tudo isso precisa ser conferido
na homologação do sandbox**; os caminhos e nomes ficam num único arquivo (`InterBankAdapter`).

PENDENTE (Djalma): conta Inter Empresas para gerar a integração de sandbox (client_id, client_secret, .crt e
.key), e a decisão de produção depois da homologação.

## Decisão

1. **Adaptador por conta bancária.** `erp_bank_accounts.adapter` (migration 0710, padrão `sandbox`).
   `BankAdapters::forAccount()` monta o adaptador da conta; conta Inter sem credencial dá erro, nunca cai no
   sandbox em silêncio. `BankCharges` e `BankReturns` passam a usar o adaptador da conta; o boleto continua no
   banco em que nasceu mesmo se a conta mudar de adaptador.
2. **`InterBankAdapter`** implementa `BankAdapter` e a interface opcional `BankChargeExtras` (PDF e leitura do
   webhook), para não quebrar adaptadores existentes. Só aceita a URL do sandbox: produção é recusada no
   construtor até outro ADR. Transporte `InterHttp` separado (`CurlInterHttp` com `CURLOPT_SSLCERT_BLOB` e
   `CURLOPT_SSLKEY_BLOB`: certificado e chave só em memória, verificação do servidor ligada).
   - Boleto híbrido: guarda linha digitável, código de barras, nosso número do banco e Pix copia e cola
     (colunas novas em `erp_bank_charges`). `seuNumero` = nosso número sequencial do ERP.
   - Pagador: responsável financeiro da unidade, senão proprietário; precisa de CPF/CNPJ. Endereço = endereço do
     condomínio, com a unidade no complemento (pessoas não têm endereço próprio, ADR 0155). Dado faltando =
     boleto **Recusado** com o motivo, sem chamar o banco. Multa e juros da régua (`BillingRules`).
   - Emissão assíncrona: depois do POST, uma consulta; se ainda processando, fica **Aguardando banco**.
     Sem `codigoSolicitacao` (timeout no POST) a consulta não reemite, para não duplicar boleto.
   - `RECEBIDO` vira evento `paid` (valor recebido em centavos, sem float); `CANCELADO`/`EXPIRADO` viram
     `cancelled`; `MARCADO_RECEBIDO` (baixa manual no Inter) **não** dá baixa: fica o aviso para conferir.
     Os eventos passam pela mesma conciliação do retorno (`BankReturns::recordEvent`): valor diferente ou título
     já pago vão para a fila de divergências.
   - Inter não tem arquivo de retorno: `parseReturn` recusa.
3. **Cofre.** `BankVault` (SecretBox com a chave `CONNECT_BANK_VAULT_KEY`, separada da do 2FA) cifra client_id,
   client_secret, certificado e chave num único campo de `erp_bank_credentials`, com a conta como contexto (AAD).
   No banco ficam só metadados: 4 últimos caracteres do client_id, titular e validade do certificado.
   Credenciais entram só pelo terminal (`tools/bank-credentials.php`), que valida o par certificado/chave e a
   validade, e pede o client_secret sem eco. Nada de formulário web nem API para segredo.
4. **Webhook** em `POST /api/v1/webhooks/bank/{adapter}/{segredo}` (rota pública, terceira exceção do
   `ApiGuardCoverageTest`). O segredo (192 bits) identifica a conta e só o sha256 fica no banco. O corpo não é
   confiável: só os `codigoSolicitacao` são lidos e **cada cobrança é consultada no banco** antes de mudar estado
   (fetch-on-notify), o que também resolve notificações repetidas e fora de ordem. A entrega é guardada cifrada
   em `erp_bank_webhook_deliveries` com sha256: processada = não roda de novo; falhou (banco sem resposta) = 503
   e roda de novo no reenvio. Evento repetido ao mesmo tempo cai na chave única e vira `duplicate`.
   Em produção, o proxy deve exigir o certificado do Inter (mTLS) e liberar só os IPs do banco.
5. **Telas.** Boletos (AP-035) mostra Pix, PDF (`GET /api/v1/bank-charges/{id}/pdf`) e o nosso número do banco.
   **Remessas (AP-037) ligada**: lotes de boletos por conta e dia com a confirmação do banco
   (`GET /api/v1/bank-remittances`), porque no Inter não há arquivo de remessa. **Pagamentos (Bancos) (AP-031)
   continua em demonstração**: é pagamento de contas pelo banco (dinheiro saindo), outra API do Inter e outro
   nível de risco; fica para decisão própria.
6. Integrações (AP-171) mostra o Inter como disponível quando o cofre tem chave.

## Consequências

- Mesma emissão repetida devolve o mesmo título; mesma notificação não baixa duas vezes; notificação forjada não
  muda nada sem confirmação do banco. Coberto por `InterBankAdapterTest` e `InterBankingIntegrationTest` com um
  Inter falso em memória e certificado autoassinado gerado no teste (nenhum dado real, nenhuma rede).
- Riscos: contrato do Inter inferido (homologar no sandbox); token em memória por processo (cada requisição PHP
  pede um token novo; com volume, guardar o token cifrado com validade); sem varredura periódica de pendentes
  (fica para a reconciliação diária do item 9 dos requisitos); certificado vence (a validade aparece em
  `tools/bank-credentials.php situacao`; alerta automático fica pendente).
- Rollback: `0710.down.sql` remove as tabelas e colunas novas; `tools/bank-credentials.php remover` volta uma
  conta ao sandbox sem migration.
