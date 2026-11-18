# 0390 — Limpeza do EXIF das imagens públicas antigas

- Status: Aceito
- Data: 18/11/2026
- Contexto: ADR 0389 (imagens novas sem EXIF) deixou de fora as imagens já gravadas em `storage/images`.

## Contexto
Desde o ADR 0389 toda imagem enviada pelo sistema é regravada sem metadados. As que já estavam em `storage/images`
(e as do site antigo, se forem levadas para a produção por `tools/migrate-legacy-images.sh`) continuam com o GPS, a data
e o aparelho de quem tirou a foto.

## Decisão
`php tools/hosting/limpar-exif.php` percorre `storage/images`:

- sem opção, só conta as imagens com metadados e não muda nada;
- com `--aplicar`, regrava só essas com `ImageMetadata::strip` (JPEG, PNG e WebP). Imagem sem metadados não é regravada,
  porque regravar JPEG de novo perde um pouco de qualidade.

`ImageMetadata::hasMetadata` reconhece pelo conteúdo: segmento APP1 (EXIF ou XMP) no JPEG; blocos `eXIf`, `tEXt`, `iTXt`
ou `zTXt` no PNG, mesmo depois da imagem; EXIF ou XMP no WebP. GIF fica fora. Um arquivo cujo conteúdo não bate com a
extensão não é regravado e sai na lista de falhas.

Saída 0 com a contagem ou com tudo limpo; 1 quando alguma imagem não pôde ser limpa.

## Consequências
- Roteiro em HOSPEDAGEM.md §3, junto da primeira implantação.
- Testes: `ImageMetadataTest::testDetectsMetadataAndCleansOnlyOldImagesThatHaveIt` (contagem sem mudar nada, limpeza só
  do que tem metadados, imagem limpa intacta byte a byte, conteúdo trocado na lista de falhas, GIF ignorado).
