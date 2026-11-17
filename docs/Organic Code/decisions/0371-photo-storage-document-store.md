# ADR 0371 — Fotos de vistoria pelo armazenamento de documentos (passo 2 do OneDrive)

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0370 (DocumentStore), ROADMAP_V1 §3.5

## Contexto

O ADR 0370 colocou o Disco Virtual atrás do `DocumentStore`. As fotos de evidência da vistoria (`PhotoStorage`) ainda
gravavam o JPEG direto na pasta `storage/private/inspections` e liam com `is_file`/`unlink`.

## Decisão

- `PhotoStorage` recebe um `DocumentStore` opcional (padrão: disco local na mesma pasta de sempre).
- A foto continua conferida, girada, reduzida e regravada como JPEG sem EXIF; o JPEG é gerado num arquivo temporário,
  o tamanho e o SHA-256 vêm dele, e ele vai para o armazenamento com `put`. O temporário é apagado sempre.
- `absolute()` e `delete()` passam pelo armazenamento, depois de conferir o formato do caminho.
- `root()` continua sendo a pasta local: o cache do relatório de vistoria em PDF (`reports/`, ADR 0335) fica no servidor.

Nada muda para o usuário nem no banco.

## Testes

`InspectionPhotoStorageTest::testStoresThroughTheDocumentStore` (armazenamento falso: grava só nele, SHA e tamanho
batem, nenhum arquivo direto no disco, temporário apagado); demais testes de foto e integração da vistoria OK.
