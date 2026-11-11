# 0205 — Fechamento do mês em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0031 (fechamento mensal), 0100 (concorrência do fechamento), 0181 (conferência antes de fechar)

## Contexto

No começo de cada mês, a administradora fecha a competência anterior de todos os condomínios da carteira. Hoje é um
por um: abrir a conferência (ADR 0181), ler os avisos e fechar. Com dezenas de condomínios:

- o fechamento atrasa;
- uma conferência com erro passa despercebida;
- um condomínio fica para trás e a prestação de contas sai sem ele.

## Decisão

Rota nova: `POST /api/v1/closings/batch` com `{competence, condominium_ids[], allow_warnings?, dry_run?}`. O service é
`MonthClosingBatch`.

### Conferência de todos primeiro

Cada condomínio passa pela conferência (`ClosingReadiness::check`). A regra pura `reasons` recusa:

- competência já fechada;
- conferência com erro (Balanço que não fecha, pago sem conta bancária), que nunca fecha em lote;
- conferência com aviso (mês anterior aberto, contas em aberto, extrato sem conciliação, saldo negativo), a não ser
  com `allow_warnings = true`. Os avisos aceitos voltam em `accepted_warnings` e ficam na auditoria.

Com alguma recusa, nada é fechado. O 422 `closing_refused` traz `refused` com todos os condomínios recusados e os
motivos (código, nível e mensagem), para corrigir tudo de uma vez.

### Depois o fechamento, tudo ou nada

- **Uma transação só:** sem recusa, fecha todos numa transação, cada um pelo `MonthClosing::close`, com a mesma foto
  do resumo, a mesma trava da competência (ADR 0031/0100) e a mesma auditoria do fechamento de um por um.
- **Corrida:** se um condomínio for fechado por outra pessoa entre a conferência e o fechamento, desfaz todos e
  devolve a recusa.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.

### Mudança no `MonthClosing::close`

Para isso, `MonthClosing::close` passa a gravar na transação de quem chamou, quando já existe uma, como o
`RegistryService::save`. Chamado sozinho (tela e API do fechamento), abre e confirma a própria transação, como antes.

### Auditoria

- A do `MonthClosing::close`, de cada condomínio.
- Um registro `close_batch` por condomínio, com a competência, o tamanho do lote, `allow_warnings` e os avisos
  aceitos, só quando grava.

### Acesso

- **Escopo (ADR-002):** todos os condomínios precisam estar no escopo; um fora dá 404 antes de consultar.
- **Limite:** até 100 condomínios por vez.
- **Permissão:** exige `erp.access`, `erp.closing.view` e `erp.closing.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados expostos:** só nomes de condomínio, códigos, mensagens e totais.

## Consequências

- **Testes.**
  - `Unit\MonthClosingBatchTest` (SQLite):
    - regra pura (aviso com e sem `allow_warnings`, erro sempre, já fechada) e ids;
    - validação, escopo (404) e o contrato do service;
    - a transação de fora no `MonthClosing`;
    - controller e rota.
  - `Integration\MonthClosingBatchTest` (MariaDB, banco `*_test`):
    - aviso recusando só quem tem e nada fechado;
    - prévia sem gravar nem auditar;
    - fechamento dos dois, com a foto e as auditorias `close_batch`;
    - repetir dá `already_closed`;
    - pago sem conta bancária recusado mesmo com `allow_warnings`, sem fechar o outro;
    - condomínio fora do escopo.
  - O `MonthClosingIntegrationTest` continua cobrindo o fechamento de um por um e a concorrência do ADR 0100.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Reabrir em lote fica de fora:** a reabertura pede motivo por condomínio e deve continuar rara.
- **Sem tela nova neste patch.** Quando a tela existir (seleção de condomínios no Fechamento mensal), ela ganha o artigo
  da Central de Ajuda.
