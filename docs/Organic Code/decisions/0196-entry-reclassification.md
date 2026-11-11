# 0196 — Reclassificação em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0022 (lançamentos), 0031 e 0100 (competência fechada), 0033/0039 (trava de origem), 0191
  (baixa em lote), 0195 (despesa parcelada)

## Contexto

Na revisão do mês, o contador ou a administradora acha lançamentos na conta errada: limpeza lançada em manutenção, uma
obra espalhada em três contas, um centro de custo (bloco, torre) esquecido. Corrigir é abrir lançamento por lançamento,
trocar a conta e salvar. Isso é lento e não deixa registrado por que tudo mudou.

## Decisão

Rota nova: `POST /api/v1/entries/reclassify` `{condominium_id, entry_ids[1..200], chart_account_id?, cost_center_id?,
reason, dry_run?}`. O service é `EntryReclassification`.

### Regras

- **O que muda:** a nova conta do plano de contas e/ou o novo centro de custo, com pelo menos um dos dois.
  `cost_center_id = 0` tira o centro de custo.
- **Regras do formulário:** cada lançamento é regravado pelo `RegistryService::save('entries')`. Vão os campos como
  estão, só com a conta e/ou o centro de custo trocados, então valem as mesmas regras:
  - conta analítica ativa, da mesma natureza do lançamento, do padrão ou do condomínio;
  - centro de custo ativo do padrão ou do condomínio;
  - competência fechada (ADR 0031, com a leitura travada do ADR 0100);
  - trava de origem: lançamento de fatura ou acordo não troca de conta aqui; o centro de custo pode;
  - auditoria `update` de cada lançamento.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Vai num registro de auditoria `reclassify` por lançamento, com o
  antes, o depois e o tamanho do lote.
- **Sem mudança:** lançamento que já está na conta e no centro pedidos não é regravado.

### Tudo ou nada

- **Transação:** com os lançamentos travados (`FOR UPDATE`).
- **Lançamento recusado ou de fora do condomínio:** dá 422 com os erros por campo e `_entry` (o id), e nada fica.
- **`dry_run`:** passa por tudo, inclusive pelas regras do formulário, e desfaz no fim (rollback). A prévia já mostra
  o antes e o depois de cada lançamento, ou a recusa.

### Permissões e escopo

- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`, as mesmas de editar lançamento.
- **CSRF:** pelo `ApiAuthenticated`.
- **Escopo:** fora do escopo dá 404 antes de consultar.
- **Consultas:** parametrizadas; a lista de ids usa marcadores `?`.

## Consequências

- **Testes:**
  - `Unit\EntryReclassificationTest` (SQLite) confere as validações, o escopo e o contrato: regravação pelo
    formulário, `FOR UPDATE`, rollback do `dry_run`, auditoria com motivo, controller e rota.
  - `Integration\EntryReclassificationTest` (MariaDB) confere:
    - o `dry_run` sem gravar;
    - a reclassificação de 2 lançamentos (conta e centro), com valor e descrição intactos e a auditoria;
    - o `0` que tira o centro de custo;
    - a competência fechada no segundo lançamento e a conta de receita, que desfazem tudo.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova neste patch.** A ação "Reclassificar selecionados" na lista de lançamentos fica para a frente de
  layout; quando a tela existir, ela ganha o artigo da Central de Ajuda.
- **Fora de escopo:** trocar natureza, valor, competência ou datas em lote. Essas mudanças afetam saldo e fechamento e
  continuam no formulário, uma a uma.
