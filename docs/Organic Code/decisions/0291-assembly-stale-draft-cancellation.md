<!-- markdownlint-disable MD024 -->
# 0291 — Rascunhos de assembleia esquecidos cancelados em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0264 (histórico das assembleias: os pendentes);
  - o fluxo das assembleias (`Assemblies`: rascunho, convocação com edital, abertura, votação, ata e cancelamento).

## Contexto

O histórico das assembleias (ADR 0264) conta como pendentes os rascunhos nunca convocados, mesmo quando a data da
primeira convocação já passou. A assembleia não aconteceu, mas o rascunho fica no cadastro e segue contando. Cancelar era
um por um (`POST /assemblies/{id}/cancel`).

O coordenador pediu uma ação em lote de outro assunto, ligada a um relatório que ainda não tinha a sua, sem mexer em
descontos.

## Decisão

Rota nova: `POST /api/v1/assemblies/cancel-stale-drafts {condominium_id, assembly_ids?, reason, dry_run?}`, logo depois
de `GET /reports/discounts` e antes das rotas `/assemblies/{id}`. O service é `AssemblyDraftCancellation`, em
`Source\Services\Erp`.

### Reuso

- **Regras e gravação do cancelamento de uma** (`Assemblies::cancel`, para o rascunho):
  - a situação passa de `draft` para `cancelled`, com o motivo em `cancel_reason` e quem cancelou em `updated_by`;
  - o `UPDATE` só vale se ainda for rascunho;
  - o mesmo registro de auditoria (`update` em `erp_assemblies`, com `notice_stopped` 0).
- **Motivo:** `Announcements::line` (5 a 255 caracteres), como o cancelamento de uma.
- **Tipos:** `Assemblies::KINDS` e os rótulos de `AssemblyHistory::LABELS`.
- **Permissão:** as mesmas de cancelar uma (`erp.assemblies.view` e `erp.assemblies.manage`).
- **Relógio:** o do banco (`SELECT NOW()`), para comparar com `first_call_at`.

### Só rascunhos

A assembleia convocada tem edital (um comunicado). O `Assemblies::cancel` cancela o edital num passo à parte, fora da
transação dele. Pôr isso no lote quebraria o tudo ou nada. A convocada segue sendo cancelada uma por uma, e o lote a
recusa com essa explicação.

### Regras

- **Entram, sem `assembly_ids`:** os rascunhos do condomínio com a primeira convocação antes de agora.
- **Com `assembly_ids`:** só os informados. A regra pura `problem()` recusa:
  - assembleia não encontrada neste condomínio;
  - que não é rascunho (convocada, aberta, encerrada ou cancelada);
  - com a primeira convocação ainda por vir. Na hora exata, ainda não passou.
- **Tudo ou nada:** numa transação, com as assembleias travadas (`FOR UPDATE`). Uma recusada desfaz todas (422, `_form` e
  `_assembly`). Se outro pedido mudou a assembleia antes, é 409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Auditoria:** um registro por assembleia (com o motivo e o tamanho do lote), só quando grava.
- **Limites:** até 500 por vez; motivo obrigatório. Nada é apagado; pauta, presença e ata não são tocadas.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.assemblies.view` e `erp.assemblies.manage`. Sem uma delas, 403 (fail-closed).
  CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, tipo, a data da primeira convocação e os dias desde ela. Não saem título, local, pauta nem quem
  criou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token. Não envia nada.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\AssemblyDraftCancellationTest`:**
  - a regra pura, com o limite da hora exata;
  - a validação e o escopo;
  - a prévia e a gravação igual à do cancelamento de uma, só nos rascunhos vencidos deste condomínio;
  - as recusas, uma por caso (futura, convocada, cancelada, encerrada, hora exata, outro condomínio, inexistente),
    desfazendo tudo;
  - nenhum título nem local;
  - o contrato (a mesma gravação e o mesmo motivo do `Assemblies::cancel`), o controller, a rota e a OpenAPI.
- **`Integration\AssemblyDraftCancellationTest`** (MariaDB, banco `*_test`):
  - assembleias de verdade, com as datas pelo relógio do banco;
  - a prévia sem gravar nem auditar;
  - a convocada recusando o lote todo;
  - a gravação com auditoria;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
