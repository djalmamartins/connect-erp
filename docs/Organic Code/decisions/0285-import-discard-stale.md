<!-- markdownlint-disable MD024 -->
# 0285 — Prévias de importação paradas descartadas em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0060 e seguintes (importações com prévia e aplicação);
  - 0281 (avisos parados cancelados em lote, de onde vêm o limite e a regra de parado);
  - 0284 (situação das importações, que aponta as prévias paradas em `pending_ids`).

## Contexto

A situação das importações (ADR 0284) mostra as prévias que ninguém aplicou: o arquivo foi conferido e esquecido. A
prévia parada confunde, porque parece pendente. Se alguém a aplicar meses depois, grava dados velhos.

Não havia como descartar uma prévia. A situação de `erp_imports` só tinha `previewed` e `applied`, e enviar o mesmo
arquivo de novo só atualizava a prévia.

## Decisão

Rota nova: `POST /api/v1/imports/discard-stale`, logo depois de `GET /reports/imports` e antes das rotas
`/imports/{id}`. O service é `ImportDiscard`, em `Source\Services\Erp`.

### Migration

A `20261114_0001_import_discard` (o número livre combinado):

- acrescenta `discarded` à enum `status` de `erp_imports`;
- acrescenta `discarded_by` (FK para `users`, `ON DELETE SET NULL`), `discarded_at` e `discard_reason`;
- é idempotente, com reversão ao lado. A reversão volta as descartadas para prévia, porque ninguém as aplicou.

Sem a migration não há como marcar a prévia sem apagá-la, e apagar perderia o histórico e o arquivo.

### Mudanças pequenas no que já existe

- **`Imports::apply`:** recusa a prévia descartada (409), com mensagem própria.
- **`Imports::preview`:** enviar o mesmo arquivo de novo volta a descartada para prévia, limpando o descarte.
- **`ImportsOverview::STATUSES` (ADR 0284):** ganha `discarded`. O teste de guarda passa a ler a enum da migration nova.
- **Tela de importações:** a descartada aparece como "Descartada", não como "Em prévia".

### Reuso

- **Tipos e rótulos:** `Imports::KINDS`.
- **Limite e regra de parado:** `NotificationStaleCancellation::cutoff` e `stale` (ADR 0281), sobre a data do envio da
  prévia, no relógio do banco (`SELECT NOW()`).

### Lote

- **Sem `import_ids`:** entram as prévias do condomínio enviadas há mais de `days` dias (padrão 30, de 1 a 365).
- **Com `import_ids`:** entram só os informados. A regra pura `problem()` recusa:
  - não encontrada no condomínio;
  - não está em prévia (já aplicada ou descartada);
  - mais nova que o limite.
- **Descartar:** `status` vira `discarded`, com quem, quando e o motivo, só para quem ainda está em prévia. O arquivo fica
  guardado e nada é aplicado nem apagado.
- **Tudo ou nada:** uma transação com as importações do condomínio travadas (`FOR UPDATE`). Uma recusada desfaz todas
  (422, com `_form` e `_import`). Uma que muda de situação no meio, 409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Até 500 importações por vez.
- **Auditoria:** um registro `discard` por importação (dias, motivo e tamanho do lote), só quando grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.imports.view` e `erp.imports.manage`, as mesmas de enviar e aplicar
  importações. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, tipo, contagem por tipo, dias e o limite. `count` é a soma por tipo. Não saem nome de arquivo,
  caminho, hash, resumo nem quem enviou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.

## Consequências

- **`Unit\ImportDiscardTest`:**
  - a regra pura, com o limite no passado;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito e sem nome de arquivo nem hash na mensagem;
  - a prévia, o limite em dias e o descarte só das prévias paradas deste condomínio, com o arquivo guardado;
  - tipos vindos de `Imports::KINDS`, sem quantidade nem lista fixa;
  - o contrato: o `Imports` recusa aplicar e reabre ao reenviar, a tela mostra "Descartada", a rota, a OpenAPI e a
    migration com reversão.
- **`Unit\ImportsOverviewTest`:** a enum nova bate com `STATUSES`.
- **`Integration\ImportDiscardTest`** (MariaDB, banco `*_test`, com a migration aplicada):
  - a prévia, as recusas com tudo desfeito e o descarte gravado, com auditoria;
  - o `Imports::apply` de verdade recusando a descartada;
  - o relatório da 0284 contando a descartada;
  - rodar de novo não muda nada;
  - o escopo.
- **Sem tela nova neste patch** (só o rótulo na tela que já existe), então sem artigo da Central de Ajuda.
