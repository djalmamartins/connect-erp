<!-- markdownlint-disable MD024 -->
# 0277 — Arquivos com vínculo de volta da lixeira, em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0047 (Disco Virtual, AP-122);
  - 0275 (cópias repetidas para a lixeira, com a lista `LINKS`);
  - 0276 (vínculos dos arquivos do Disco Virtual, que aponta os vínculos para a lixeira).

## Contexto

A exclusão no Disco Virtual (ADR 0047) é lógica: `deleted_at` e `deleted_by`. O relatório de vínculos (ADR 0276) mostra
os cadastros que apontam para um arquivo que foi para a lixeira. O contrato, o seguro ou o malote mostra "documento
removido".

Corrigir hoje é tirar da lixeira arquivo por arquivo, e não há tela nem rota para isso. Busquei `deleted_at = NULL`,
`restore` e `erp_documents` em `source/`, no README das ADRs e nos handoffs 02xx. O `VirtualDisk` não tem restauração.

## Decisão

Rota nova: `POST /api/v1/documents/restore-linked`, logo depois de `GET /reports/document-links` e antes das rotas
`/documents/{id}`. O service é `VirtualDiskRestore`, em `Source\Services\Erp`.

### Reuso

- **Cadastros que guardam arquivo:** `VirtualDiskDeduplication::LINKS`, pela consulta do relatório de vínculos. A
  consulta saiu do `VirtualDiskLinks::build` para o método público `linkCounts()`, sem mudar o relatório.
- **Restauração:** o `VirtualDisk` não tem método de restaurar. O service faz o inverso da `VirtualDisk::delete`:
  `deleted_at` e `deleted_by` voltam a `NULL`, com auditoria `restore` em `erp_documents`.
- **Arquivo físico:** nunca saiu do disco. Nada é apagado nem copiado.

### Lote

- **Sem `document_ids`:** entram os arquivos da lixeira do condomínio com algum vínculo.
- **Com `document_ids`:** entram só os informados. A regra pura `problem()` recusa:
  - arquivo não encontrado no condomínio;
  - fora da lixeira;
  - sem vínculo;
  - com outro arquivo igual (mesmo `sha256`) fora da lixeira na mesma pasta, o que o envio recusa.
- **Iguais no próprio lote:** os restaurados contam como fora da lixeira, em ordem de id. De dois iguais na mesma pasta,
  volta o mais antigo. Sem ids, os iguais ficam de fora e a contagem sai em `skipped_duplicate`.
- **Tudo ou nada:** uma transação com os arquivos do condomínio travados (`FOR UPDATE`). Um recusado desfaz todos (422,
  com `_form` e `_document`).
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Até 500 arquivos por vez.
- **Auditoria:** um registro `restore` por arquivo (pasta, vínculos, motivo e tamanho do lote), só quando grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.documents.view` e `erp.documents.manage`, as mesmas da exclusão no Disco
  Virtual. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** id, pasta, bytes e vínculos. Totais `count`, `links` e `bytes` iguais à soma das linhas. Não saem
  título, nome de arquivo, caminho ou hash.
- **Nenhum aviso** é enviado. Não toca o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.**

## Consequências

- **`Unit\VirtualDiskRestoreTest`:**
  - a regra pura e a soma dos vínculos;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito e sem título, nome, caminho ou hash na mensagem;
  - de dois iguais, volta o mais antigo;
  - a prévia sem ids e os totais iguais à soma das linhas;
  - cadastros vindos de `LINKS` (o primeiro e o último), sem quantidade nem lista fixa;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\VirtualDiskRestoreTest`** (MariaDB, banco `*_test`):
  - envios, exclusões e malotes de verdade;
  - a prévia, as recusas com tudo desfeito e a restauração gravada com auditoria;
  - o arquivo volta para o Disco Virtual, e o relatório de vínculos fica só com o que tem igual fora da lixeira;
  - rodar de novo não muda nada;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. O inventário do Disco Virtual pode ganhar o botão;
  quando ganhar, vem o artigo.
