# ADR 0372 — Foto do morador pelo armazenamento de documentos (passo 3 do OneDrive)

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0370 (DocumentStore), ADR 0371 (fotos de vistoria), ADR 0302 (foto do morador no app), ROADMAP_V1 §3.5

## Contexto

Depois do Disco Virtual (0370) e das fotos de vistoria (0371), a foto da conta do morador (`ResidentProfile`) ainda
gravava direto em `storage/private/resident-photos` e apagava com `unlink`.

## Decisão

- `ResidentProfile` recebe um `DocumentStore` opcional (padrão: disco local na pasta de sempre).
- A foto continua recortada em quadrado, reduzida a 512 px e regravada como JPEG sem EXIF, num temporário que vai para o
  armazenamento com `put` e é sempre apagado.
- Trocar a foto apaga a antiga no armazenamento; apagar a foto também. O formato do caminho continua conferido antes.

Nada muda para o morador nem no banco. Ficam as importações (arquivos temporários de planilha, que não precisam ir para o
OneDrive) e a implementação do OneDrive, que depende da conta Microsoft 365 da Connect.

## Testes

`ResidentProfileIntegrationTest::testPhotoGoesThroughTheDocumentStore` (armazenamento falso: grava só nele, troca apaga a
antiga, apagar remove); demais testes do perfil, do app e do portal do morador OK.
