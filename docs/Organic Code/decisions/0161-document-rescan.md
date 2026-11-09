# 0161 — Reverificação dos arquivos do Disco Virtual quando o antivírus é ligado depois

Data: 2026-11-09 · Status: proposto para revisão · Continuação dos ADRs 0098 e 0160

## Contexto

Com `ERP_AV_MODE=off`, que é o padrão, os arquivos entram com `scan_status=unscanned`. Quando o antivírus
for ligado, esses arquivos antigos continuariam sem verificação.

## Decisão

- **Comando** `php tools/erp-rescan.php`, no mesmo padrão do `tools/notifications-dispatch.php`. O
  agendamento fica com a infraestrutura; não há cron no código. Cada execução roda um lote do
  `Antivirus\DocumentRescan`:
  - **Seleção:** até 50 arquivos com `scan_status` `unscanned` ou `error` e `scanned_at` nulo ou com mais
    de 24 h, do mais antigo para o mais novo. Os excluídos também entram, porque o arquivo continua
    guardado.
  - **Verificação:** cada arquivo passa pelo scanner configurado (`ERP_AV_ENDPOINT`), e o comando grava
    `scan_status` e `scanned_at`. O UPDATE é condicionado ao status anterior.
  - **Arquivo que sumiu da pasta:** vira `error` e é tentado de novo depois de 24 h.
  - **Modo off:** com `ERP_AV_MODE=off`, o comando não faz nada (status `off`).
  - **Scanner desligado:** não marca nada (status `unavailable`).
  - **Falha no meio do lote:** o lote para ali, sem marcar o arquivo da falha (status `interrupted`).
  - **Trava:** `GET_LOCK('erp_document_rescan', 0)` no MySQL, ou `flock` num arquivo temporário nos
    outros bancos. Se outra execução já estiver rodando, o lote é pulado (status `locked`).
  - **Auditoria:** um resumo por lote em `system_audit_logs`, na entidade `erp_documents_rescan`, só com
    as contagens de clean, infected e error. Não leva nome, caminho nem id de arquivo.
  - **Log:** arquivo infectado gera um aviso no log só com o id.
  - **Saída:** uma linha JSON. O código de saída é 2 quando o antivírus está indisponível ou falhou no
    meio do lote, e 0 nos outros casos.
- **Integrações** (`IntegrationStatus`, ADR 0091; API `/api/v1/integrations`, com `erp.settings.view`):
  ganha o bloco `antivirus`, só com números, que traz:
  - o modo efetivo;
  - se há antivírus configurado (sim ou não, sem o endereço);
  - a contagem de arquivos não excluídos por `scan_status`, só dos condomínios do escopo;
  - o total pendente de reverificação.

## Consequências

- `DocumentRescanTest` cobre:
  - lote vazio;
  - modo off e scanner indisponível;
  - falha no meio do lote;
  - clean, infected e arquivo que sumiu marcados, com a auditoria sem nome nem caminho;
  - a espera de 24 h depois de um erro;
  - o limite de 50 por lote;
  - a trava;
  - o bloco das Integrações.
- Sem migration: as colunas são as do ADR 0098 (migration 0546).
- A tela de Integrações (AP-171) mostra o bloco `antivirus` que a API já devolve. Ela usa o mesmo `IntegrationStatus`,
  exige `erp.settings.view` e só consulta. O `render-smoke` cobre o antivírus desligado e o ligado sem endereço.
- O agendamento de `php tools/erp-rescan.php` fica pendente até haver clamd no ambiente (issue #46).
