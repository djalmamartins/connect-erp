# 0203 — Cancelamento de contas em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0191 (baixa em lote), 0192 (estorno em lote), 0194 (travas de cancelamento e exclusão), 0197
  (prorrogação em lote)

## Contexto

Acontece toda semana na administradora:

- uma importação entra em dobro;
- um contrato é encerrado e as parcelas futuras já estavam lançadas;
- uma despesa é lançada no condomínio errado.

Hoje cada conta é cancelada no formulário, uma por uma. O lote existe para baixa, estorno, parcelamento,
reclassificação e prorrogação, mas não para cancelamento.

## Decisão

Rota nova: `POST /api/v1/entries/cancel` com `{condominium_id, entry_ids[], reason, dry_run?}`. O service é
`EntryCancellation`.

### O que acontece

Cancelar não exclui: a conta fica com a situação "Cancelado" e sai dos relatórios e da cobrança. Cada conta é
regravada pelo `RegistryService::save` do cadastro `entries`, só com `status = 'inactive'`, então valem as regras do
formulário:

- competência fechada (ADR 0031/0100);
- trava de origem: fatura, acordo, cobrança extra ou multa se cancelam pela origem (ADR 0033/0039);
- recibo válido (ADR 0057);
- conta conciliada ou com boleto pago pelo banco (ADR 0194).

A auditoria `update` vem do formulário. Um registro `cancel` guarda o motivo e o tamanho do lote.

### Recusas antes do formulário

A regra pura `problem` recusa:

- conta não encontrada no condomínio (ou excluída);
- já cancelada;
- já paga: estorne a baixa antes (ADR 0192);
- com boleto pendente ou registrado no banco: o título continuaria no banco; cancela-se pela cobrança, e aqui nada do
  fluxo do banco é chamado.

### Gravação

- **Tudo ou nada:** numa transação com as contas travadas (`FOR UPDATE`), uma recusada desfaz todas. O 422 traz os
  erros e `_entry`.
- **Prévia:** `dry_run` passa por tudo, inclusive pelas regras do formulário, e desfaz no fim.
- **Limites:** um condomínio só, do escopo (fora dá 404); até 200 contas (`BatchSettlement::MAX_ENTRIES`); motivo
  obrigatório (5 a 255 caracteres).

### Acesso

- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Consultas:** parametrizadas, com marcadores `?`.

## Consequências

- **Testes.**
  - `Unit\EntryCancellationTest` (SQLite): regra pura, ids, validação, escopo (404), controller, transação, auditoria e
    rota.
  - `Integration\EntryCancellationTest` (MariaDB, banco `*_test`):
    - prévia sem gravar e o cancelamento, só com a situação mudando;
    - duas auditorias `cancel`;
    - recusa de cancelada, paga, com boleto registrado, com boleto pago (trava do ADR 0194) e em mês fechado;
    - tudo ou nada.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Reativar** uma conta cancelada continua pelo formulário.
- **Sem tela nova neste patch.** Quando a tela existir, ela ganha o artigo da Central de Ajuda.
