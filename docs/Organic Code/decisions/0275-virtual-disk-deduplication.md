<!-- markdownlint-disable MD024 -->
# 0275 — Cópias repetidas do Disco Virtual para a lixeira

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0047 (Disco Virtual, AP-122, com a exclusão lógica e auditada);
  - 0274 (inventário do Disco Virtual, que conta as cópias).

## Contexto

O Disco Virtual (ADR 0047) recusa o mesmo arquivo (sha256) só dentro da mesma pasta. A mesma ata vai para Atas e para
Outros, e o mesmo contrato para Contratos e para Notas fiscais.

O inventário (ADR 0274) mostra quantas cópias há. Tirar cada uma é abrir arquivo por arquivo.

Busquei `erp_documents` e `sha256` em `source/`, no README das ADRs e nos handoffs 02xx. Nenhum serviço tira as cópias
repetidas.

## Decisão

Rota nova: `POST /api/v1/documents/trash-duplicates {condominium_id, document_ids?, reason, dry_run?}`, logo depois de
`GET /reports/virtual-disk` e antes das rotas `/documents/{id}`. O service é `VirtualDiskDeduplication`, em
`Source\Services\Erp`.

### Reuso e segurança do arquivo

- **Exclusão:** cada cópia vai para a lixeira pelo `VirtualDisk::delete`, a mesma exclusão lógica e auditada da tela. **O
  arquivo físico continua guardado**: nada é apagado do disco.
- **Cópia que fica:** sempre a mais antiga (o menor id) de cada conteúdo.
- **Arquivo ligado a outro cadastro não sai:** o vínculo apontaria para um arquivo na lixeira. Contam como ligados os
  usados por contrato, seguro, manutenção, ocorrência, empenho, cópia de cheque, aviso de entrega, malote, procuração de
  assembleia ou anexo de lançamento (ADR 0174), as tabelas com chave para `erp_documents`. Também não sai a cópia
  compartilhada com os moradores (ADR 0302), que sumiria do portal; conta como ligada.

### Quais entram

Entram as cópias não excluídas do condomínio além da mais antiga de cada conteúdo, ou só os `document_ids` informados.

### Regra pura `keepers()` e `problem()`

Recusa o arquivo:

- não encontrado no condomínio (ou já na lixeira);
- sem outra cópia;
- a cópia que fica (a mais antiga);
- ligado a outro cadastro: troque o arquivo lá antes.

Sem ids, os ligados ficam de fora e a contagem sai em `skipped_linked`. Com ids, um recusado desfaz todos (`_form` e
`_document`).

### Execução

- **`groups` e `bytes`:** quantos conteúdos tiveram cópias tiradas e quanto elas somam.
- **Tudo ou nada:** uma transação com todos os arquivos do condomínio travados (FOR UPDATE), porque a cópia que fica
  depende do conjunto. `dry_run` passa por tudo e desfaz, inclusive a auditoria da exclusão, que vai na mesma transação.
- **Auditoria:** a delete do Disco Virtual e um registro `trash_duplicate` por cópia (a cópia que fica e o motivo), só
  quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.documents.view` e `erp.documents.manage`, as mesmas da exclusão no Disco Virtual.
  Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou arquivo recusado, 422, e nada vai para a lixeira.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, pasta, bytes e o id da cópia que fica. Não saem título, nome de arquivo, caminho ou hash. Nenhum
  aviso é enviado.
- **Não toca** o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VirtualDiskDeduplicationTest`:**
  - a regra pura (a mais antiga fica, em qualquer ordem) e as recusas;
  - a validação e o escopo;
  - as recusas antes da exclusão, uma por caso, sem título nem nome na mensagem;
  - a cópia livre passando pela recusa;
  - os ligados (contrato, procuração, malote) de fora;
  - o contrato de reuso (sem `unlink` nem `UPDATE` próprio), o controller e a rota.
- **`Integration\VirtualDiskDeduplicationTest`** (MariaDB, banco `*_test`):
  - envios de verdade do mesmo PDF em três pastas;
  - a prévia sem gravar, inclusive sem auditoria;
  - as duas cópias na lixeira, a mais antiga e o arquivo sem cópia fora dela;
  - os arquivos físicos ainda no disco;
  - as auditorias delete e `trash_duplicate`;
  - nenhum aviso e nenhum título, nome ou hash;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. O inventário do Disco Virtual pode ganhar o botão
  "Mandar as cópias para a lixeira"; quando ganhar, vem o artigo.
