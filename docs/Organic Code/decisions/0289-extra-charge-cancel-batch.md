<!-- markdownlint-disable MD024 -->
# 0289 — Cobranças extras e multas avulsas canceladas em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0288 (situação das cobranças extras e multas avulsas);
  - 0057 e 0080 (cobrança extra e multa avulsa, e o cancelamento de uma: `EntryShortcuts::cancelCharge`);
  - 0194 (travas do banco ao cancelar uma conta: conciliação com o extrato e boleto pago pelo banco);
  - 0100 (leitura travada do fechamento mensal dentro da transação).

## Contexto

Quando várias cobranças extras ou multas são lançadas por engano, cancelar é uma por vez. Cada cancelamento confere a
competência, as contas pagas, os acordos e os boletos ativos.

## Decisão

Rota nova: `POST /api/v1/extra-charges/cancel-batch {condominium_id, charge_ids, reason, dry_run?}`, logo depois de
`GET /reports/extra-charges` e antes das rotas `/extra-charges/{id}`. O service é `ExtraChargeCancellation`, em
`Source\Services\Erp`.

### Reuso

- **Regras e gravação do cancelamento de uma** (`EntryShortcuts::cancelCharge`):
  - a cobrança ativa;
  - a competência aberta (`RegistryService::isClosed`, com a leitura travada do ADR 0100);
  - nenhuma conta paga, nenhuma em acordo e nenhum boleto ativo (`pending` ou `registered`);
  - as mesmas mensagens;
  - a mesma gravação: as contas da cobrança inativas, e a cobrança `cancelled` com quem cancelou, quando e o motivo;
  - o mesmo registro de auditoria (`update` em `erp_extra_charges`).
- **Travas do banco do ADR 0194** (`PaymentLocks::find` e `removal`): conta conciliada com o extrato ou com boleto pago
  pelo banco não é cancelada. O cancelamento de uma não confere essa trava; o lote confere, como pedido.
- **Motivo e tipos:** `EntryShortcuts::reason` (5 a 255 caracteres) e `EntryShortcuts::CHARGE_KINDS`.
- **Permissão:** as mesmas de cancelar uma (`EntryShortcuts::INVOICES`: `erp.invoices.view` e `erp.invoices.manage`).

Não dá para chamar `cancelCharge` dentro do lote: ele abre e fecha a própria transação, e o lote precisa ser tudo ou
nada. Por isso as regras são repetidas em `problem()`, e o teste unit confere que as mensagens e os filtros são os mesmos
do `cancelCharge`. O `EntryShortcuts` não muda.

### Regras

- **`charge_ids` é obrigatório** (de 1 a 500). Não há modo "todas as ativas": cancelar tudo de uma vez seria perigoso.
- **Regra pura `problem()`** recusa:
  - cobrança não encontrada neste condomínio;
  - já cancelada;
  - competência fechada;
  - conta paga;
  - conta em acordo;
  - boleto ativo;
  - trava do banco.
  Conta já cancelada sem acordo não impede, como no cancelamento de uma.
- **Tudo ou nada:** numa transação, com as cobranças e as contas travadas (`FOR UPDATE`). Uma recusada desfaz todas (422,
  `_form` e `_charge`). O `UPDATE` da cobrança só vale com `status = 'active'`; se outro pedido mudou antes, é 409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Auditoria:** um registro por cobrança (com o motivo e o tamanho do lote), só quando grava.
- **Nada é apagado.**

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.invoices.view` e `erp.invoices.manage`. Sem uma delas, 403 (fail-closed). CSRF
  pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, tipos, quantidade de contas e valores (só das contas que estavam ativas). Não saem descrição,
  motivo, unidade nem quem lançou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ExtraChargeCancellationTest`:**
  - a regra pura, uma recusa por regra;
  - a validação, com os ids obrigatórios, e o escopo;
  - a prévia e a gravação igual à do cancelamento de uma, sem apagar;
  - as recusas no lote (conta paga, acordo, boleto ativo, conciliação, já cancelada, competência fechada, outro
    condomínio), desfazendo tudo;
  - o relatório da 0288 depois;
  - nenhuma descrição, motivo nem unidade;
  - o contrato (as mesmas mensagens e filtros do `cancelCharge`), o controller, a rota e a OpenAPI.
- **`Integration\ExtraChargeCancellationTest`** (MariaDB, banco `*_test`):
  - cobranças e contas de verdade;
  - a prévia sem gravar nem auditar;
  - a conta paga recusando o lote todo;
  - a gravação com auditoria;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
