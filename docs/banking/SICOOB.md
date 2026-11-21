# Sicoob — integração de cobrança (ADR 0633)

Situação: **só o sandbox do Sicoob**. Produção depende de homologação e aprovação explícita do Djalma.

## O que está pronto

| Capacidade | Como |
| --- | --- |
| Autenticação (sandbox) | cabeçalhos `client_id` e `Authorization: Bearer` com o client ID e o access token fixos do portal; sem certificado |
| Emitir boleto com Pix | `POST /api/v1/bank-charges` (tela Boletos) e em lote pela fatura (Gerar boletos) → `POST /cobranca-bancaria/v3/boletos` |
| Consultar | botão Consultar e a consulta automática de hora em hora (`tools/bank-charges-sync.php`) → `GET /cobranca-bancaria/v3/boletos` |
| Cancelar (baixa) | `POST /api/v1/bank-charges/{id}/cancel` → `POST /cobranca-bancaria/v3/boletos/{nossoNumero}/baixar` |
| PDF do banco | `GET /api/v1/bank-charges/{id}/pdf` → segunda via com `gerarPdf=true` (o boleto da Connect continua com o layout próprio) |
| Extrato e saldo | Importações > Extrato > **Buscar extrato do banco** → `/conta-corrente/v3/extrato/{mes}/{ano}` e `/conta-corrente/v2/saldo` |
| Simular pagamento | NÃO existe no sandbox do Sicoob (o botão não aparece) |
| Webhook | PENDENTE (precisa de endereço público) |

## Passo a passo para o Djalma (sandbox)

1. Entre em **developers.sicoob.com.br** e crie o login (é gratuito, não precisa ser cooperado para o sandbox).
2. Abra **Sandbox**. O portal mostra um **client ID** e um **access token** fixos. Copie os dois.
3. No Connect ERP, em **Financeiro > Contas bancárias**, cadastre a conta do condomínio com o banco **756** (Sicoob),
   agência e conta corrente. Em **Carteira**, deixe vazio (modalidade 1, cobrança simples com registro) ou ponha o
   número da modalidade que a cooperativa informar.
4. Em **Configurações > Integrações**, card **Banco Inter, Cora e Sicoob**: escolha a conta do Sicoob, cole o
   **client ID**, o **access token do sandbox** e o **número do cliente** (no sandbox pode ser qualquer número, por
   exemplo 25546454; em produção é o código do beneficiário da cooperativa). Não precisa de certificado.
5. Clique em **Testar e gravar**: o sistema só faz uma consulta, não emite nada.
6. Nunca mande o token ou o client ID pelo chat.

## Para a produção (depois, com aprovação)

- O condomínio precisa ter conta e **convênio de cobrança** na cooperativa (o gerente libera a Cobrança Bancária).
- No portal, criar o **aplicativo** de produção, enviar o **certificado A1** (e-CNPJ) e pedir os escopos
  `boletos_inclusao boletos_consulta boletos_alteracao` (e `cco_extrato cco_saldo` para extrato, a conferir).
- Pegar com a cooperativa o **número do cliente** (código do beneficiário) e a **modalidade**.
- Liberar no código a URL `https://api.sicoob.com.br` e o token em `auth.sicoob.com.br` (novo ADR).

## Homologação no sandbox (checklist)

- [ ] Teste da credencial passa (tela Integrações, botão Testar).
- [ ] Emitir boleto: volta nosso número, linha digitável, código de barras e `qrCode` (Pix).
- [ ] Anotar o que o sandbox devolve em `seuNumero` e `nossoNumero` (relatos dizem que é fixo e pode vir negativo).
- [ ] Consultar: anotar o texto exato de `situacaoBoleto` (Em Aberto, Liquidado, Baixado...) e se há `valorPago`/`dataLiquidacao`.
- [ ] Baixar (cancelar) um boleto: resposta 204.
- [ ] Segunda via em PDF (`pdfBoleto` em base64).
- [ ] Extrato e saldo: formato do `valor` (texto com ponto?) e do `tipo` (CREDITO/DEBITO).
- [ ] Conferir no PDF a multa 2% e os juros: `tipoJurosMora = 2` é **taxa mensal** (INFERIDO).
- [ ] Erro de validação aparece como Recusado com a mensagem do Sicoob (`mensagens[].mensagem`).

Itens INFERIDOS estão no ADR 0633 e no cabeçalho de `source/Services/Erp/Banking/Sicoob/SicoobBankAdapter.php`.
