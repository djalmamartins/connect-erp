<!-- markdownlint-disable MD024 -->
# 0276 — Vínculos dos arquivos do Disco Virtual

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0047 (Disco Virtual, AP-122);
  - 0274 (inventário do Disco Virtual);
  - 0275 (cópias repetidas para a lixeira, que não tira arquivo ligado).

## Contexto

Vários cadastros apontam para arquivos do Disco Virtual (ADR 0047): contrato, seguro, manutenção, ocorrência, empenho,
cópia de cheque, aviso de entrega, malote, procuração de assembleia e anexo de lançamento. O inventário (ADR 0274) conta
arquivos e espaço, e o lote de cópias (ADR 0275) não tira arquivo ligado.

A administradora não vê, de uma vez:

- quantos arquivos de cada pasta estão ligados a algum cadastro e quantos estão soltos;
- quantos arquivos cada cadastro usa;
- principalmente, quantos vínculos apontam para um arquivo que já foi para a lixeira: o cadastro mostra "documento
  removido" e precisa ser corrigido.

Busquei `erp_documents` e `document_id` em `source/`, no README das ADRs e nos handoffs 02xx. Nenhum relatório cruza os
arquivos com os cadastros que os usam.

## Decisão

Rota nova: `GET /api/v1/reports/document-links?condominium_id=`, logo depois de `POST /documents/trash-duplicates` e antes
do laço dos cadastros. O service é `VirtualDiskLinks`, em `Source\Services\Erp`.

### Reuso

- **Pastas:** `VirtualDisk::FOLDERS`.
- **Cadastros que guardam arquivo:** `VirtualDiskDeduplication::LINKS`, a mesma lista que protege as cópias ligadas. Um
  cadastro novo entra nos dois lugares de uma vez.
- **Escopo do vínculo:** o vínculo conta pelo arquivo do condomínio para o qual aponta.

### Regra pura `summarize()`

- **`folders`:** uma linha por pasta, sempre todas as de `VirtualDisk::FOLDERS`, com `documents` (não excluídos),
  `linked` e `loose`, que somam `documents`. `totals` soma as linhas.
- **`registries`:** uma linha por cadastro, sempre todos os de `LINKS`, com `documents` (arquivos não excluídos que ele
  usa), `links` e `broken_links` (vínculos para arquivos na lixeira).
- **`broken`:** `links` (a soma dos `broken_links`), `documents` (arquivos distintos na lixeira com vínculo) e
  `document_ids` (até 200, com `truncated`), para abrir e corrigir.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.documents.view`, as mesmas do inventário. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só pastas, cadastros, contagens e ids de arquivo. Não saem título, nome de arquivo, caminho ou hash.
- **Não toca** o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VirtualDiskLinksTest`:**
  - a regra pura: ligados e soltos por pasta, arquivos e vínculos por cadastro, vínculos para a lixeira e o corte dos ids;
  - totais iguais à soma das linhas;
  - outro condomínio de fora;
  - nenhum título, nome, caminho ou hash;
  - pastas e cadastros vindos das próprias constantes, sem quantidade nem lista fixa no teste;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\VirtualDiskLinksTest`** (MariaDB, banco `*_test`):
  - envios de verdade e um malote ligado a um arquivo;
  - um malote ligado a um arquivo que foi para a lixeira (o vínculo quebrado);
  - os cadastros vindos de `LINKS`;
  - somente leitura e sem título, nome ou hash;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. O inventário do Disco Virtual pode ganhar o quadro
  dos vínculos quebrados; quando ganhar, vem o artigo.
