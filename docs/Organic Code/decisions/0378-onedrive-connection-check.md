# ADR 0378 — Conferência da ligação com o OneDrive

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADRs 0373, 0374 e 0376, HOSPEDAGEM.md §10

## Contexto

O `deploy-check` conferia só se as chaves do OneDrive estavam no `.env`. Um segredo errado, permissão sem consentimento ou
id de drive trocado só apareceria quando alguém enviasse ou abrisse um arquivo, e a cópia dos arquivos existentes falharia
arquivo por arquivo.

## Decisão

- `OneDriveDocumentStore::probe()`: pede o token e lê o drive (`GET /drives/{id}?$select=id`). Devolve null quando está
  tudo certo, ou o motivo em português: autorização recusada, sem permissão (`Files.ReadWrite.All` e consentimento),
  drive não encontrado (`ONEDRIVE_DRIVE_ID`) ou outro código HTTP. O segredo nunca aparece na mensagem.
- `deploy-check`: com `DOCUMENT_STORAGE=onedrive` e as chaves completas, roda a conferência; problema vira **erro**.
- `tools/hosting/onedrive-copy.php`: confere antes de copiar e sai com 2 se não houver ligação.

## Testes

`OneDriveDocumentStoreTest::testProbeExplainsWhatIsWrong` (200, 403, 404, 500, token recusado, endereço do drive
codificado, sem segredo na mensagem); `StorageCopyTest` (a cópia confere antes de enviar; o deploy-check chama a
conferência).
