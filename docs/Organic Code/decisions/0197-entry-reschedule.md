# 0197 — Prorrogação de vencimento em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0196 (reclassificação em lote), 0195 (despesa parcelada), 0031 e 0100 (competência fechada),
  0033/0039 (trava de origem), 0057 (trava de recibo), 0035 (boletos)

## Contexto

O vencimento das contas muda o tempo todo: o fornecedor aceita pagar dia 25 em vez de 10, a assembleia adia uma obra,
um feriado bancário empurra tudo alguns dias, as parcelas de uma compra (ADR 0195) mudam juntas. Hoje cada conta é
aberta e salva de novo, uma a uma.

## Decisão

Rota nova: `POST /api/v1/entries/reschedule` `{condominium_id, entry_ids[1..200], due_on | days, reason, dry_run?}`. O
service é `EntryReschedule`.

### Regras

- **Novo vencimento:** uma data para todas (`due_on`) ou um deslocamento de cada vencimento em dias (`days`, de −365
  a 365, diferente de zero). É um ou outro, nunca os dois (regra pura `newDueOn`).
- **Só conta em aberto e válida.** Conta paga, cancelada ou de fora do condomínio recusa o lote.
- **Boleto ativo no banco:** conta com boleto `pending`, `registered` ou `paid` em `erp_bank_charges` também recusa. O
  título registrado continuaria com o vencimento antigo; muda-se pela cobrança. Aqui é só leitura; o fluxo do banco e o
  Inter (AP-031/AP-037) não mudam.
- **A competência não muda,** porque é do fato e não do vencimento.
- **Regras do formulário:** cada conta é regravada pelo `RegistryService::save('entries')` só com o vencimento trocado.
  Valem:
  - a competência fechada (ADR 0031/0100);
  - a trava de origem (fatura e acordo não mudam de vencimento aqui);
  - a trava de recibo;
  - a auditoria `update`.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Vai num registro de auditoria `reschedule` por conta, com o vencimento
  antes e depois.

### Tudo ou nada

- **Transação:** com as contas travadas (`FOR UPDATE`).
- **Conta recusada:** dá 422 com os erros e `_entry`, e nada fica.
- **`dry_run`:** passa por tudo, inclusive pelas regras do formulário, e desfaz no fim.

### Permissões e escopo

- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`.
- **CSRF:** pelo `ApiAuthenticated`.
- **Escopo:** fora do escopo dá 404 antes de consultar.
- **Consultas:** parametrizadas; a lista de ids usa marcadores `?`.

## Consequências

- **Testes:**
  - `Unit\EntryRescheduleTest` (SQLite) confere:
    - a regra pura: data fixa, dias, fim de fevereiro, virada do ano;
    - as validações e o escopo;
    - o contrato: regravação pelo formulário, `FOR UPDATE`, rollback do `dry_run`, consulta dos boletos, auditoria,
      controller e rota.
  - `Integration\EntryRescheduleTest` (MariaDB) confere:
    - o `dry_run` sem gravar;
    - o deslocamento de 15 dias e a data fixa, com a competência intacta e a auditoria;
    - conta paga, conta com boleto registrado e mês fechado, cada um desfazendo o lote.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova neste patch.** A ação "Prorrogar selecionadas" fica para a frente de layout; quando a tela existir,
  ela ganha o artigo da Central de Ajuda.
- **Fora de escopo:** recalcular multa e juros. Eles seguem as regras de cobrança (`BillingRules`, `LateCharges`) pelo
  vencimento novo.
