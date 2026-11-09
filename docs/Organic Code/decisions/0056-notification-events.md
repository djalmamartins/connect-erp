# 0056 — Avisos de cobrança, acordo e reserva pela outbox

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

A outbox (ADR 0054) e a fila de envio (ADR 0055) só tinham o aviso da lista de espera. A coordenação
pediu os avisos de fatura emitida, boleto emitido, acordo com as parcelas, quebra de acordo e reserva
aprovada. Cada aviso tem modelo próprio no container send e segue a mesma regra de entrega: fora de
produção, nada vai para morador. O opt-in do morador vale onde se aplica.

O cadastro de pessoas (AP-005) já tem o opt-in de cobrança: "Recebe boleto por e-mail"
(`billing_by_email`), que exige e-mail quando marcado.

## Decisão

- **Eventos novos em `NotificationOutbox::EVENTS`**, cada um gravado na mesma transação da ação:
  - `invoice.issued`: na emissão da fatura (`Invoices::issue`), um por unidade, com tipo, competência,
    vencimento e valor;
  - `bank_charge.issued`: quando o banco registra o boleto (`BankCharges::apply`, passagem para
    `registered`, na emissão ou na consulta), com valor, vencimento e linha digitável; conta sem
    unidade não gera aviso;
  - `agreement.created`: na criação do acordo, com número, total, quantidade de parcelas, valor da
    primeira e primeiro e último vencimento;
  - `agreement.breached`: na quebra (`Agreements::breach`, agora numa transação), com número, total e
    parcelas em atraso. O motivo da quebra é anotação interna e **não** vai no aviso;
  - `reservation.approved`: pela chave nova `notify` do motor de cadastros, quando a reserva passa de
    "aguardando aprovação" para "confirmada". Criar já confirmada (área de reserva livre) ou salvar de
    novo não avisa.
- **`notify` no motor** (`RegistryService::notification`, regra pura): `event`, `from`, `to` e
  `payload` (coluna → chave). A gravação e o aviso vão na mesma transação.
- **Público por aviso** (`NotificationDispatcher::NOTICES`):
  - `billing` (fatura, boleto, acordo e quebra): só quem marcou o opt-in `billing_by_email`, com
    e-mail válido. Responsável financeiro e proprietário vêm primeiro, até 5 pessoas. Sem ninguém
    autorizado, o aviso é cancelado com o motivo;
  - `unit` (lista de espera e reserva): pessoas ativas da unidade com e-mail.
- **Modelos** em `container/send/default/v1/pages/`: `invoice-issued`, `bank-charge-issued`,
  `agreement-created`, `agreement-breached` e `reservation-approved`. Todas as variáveis são
  escapadas. Valores (`*_cents`) e datas são formatados por `NotificationDispatcher::formatPayload`
  (regra pura); ids não entram no modelo.
- **Entrega:** a mesma do ADR 0055: log por padrão, caixa de teste no sandbox, morador só com
  `APP_ENV=production` e `ERP_MAIL_DELIVERY=production`.
- **Sem migration:** `billing_by_email` e as tabelas da outbox já existem. O número 0534 reservado para
  este ciclo não foi usado.

## Consequências

- Um teste unitário garante que todo evento da outbox tem aviso, modelo existente e público válido.
  Os avisos de cobrança ficam sempre no público `billing`.
- Antes de ligar o modo production, continua valendo a pendência do ADR 0055 (credenciais SMTP do
  ambiente). Também é preciso confirmar com a administração o texto dos avisos de cobrança e o de quebra.
- Avisos futuros (entrega na portaria, reserva recusada) seguem o mesmo caminho: evento, modelo e
  público.
