# 0389 — Imagens públicas sem EXIF (GPS e aparelho)

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.7 (revisão dos envios, continuação do ADR 0388) e §3.8 (LGPD).

## Contexto
Foto de celular leva no EXIF a localização (GPS), a data e o modelo do aparelho. As fotos de vistoria e a foto do
morador já eram regravadas como JPEG, o que tira esses dados. Mas o `Upload::image` do núcleo, usado pelas imagens
do Studio (blog, páginas, equipe, depoimentos, banners, Central de Ajuda), pela foto do condomínio e pelas imagens
anexadas aos chamados da Central de Ajuda, só regravava a imagem quando ela era mais larga que o limite. Uma foto
menor ia para `storage/images` (pasta pública) com o GPS de quem tirou. Quando era regravada, a orientação do EXIF se
perdia e a foto em pé ficava deitada.

## Decisão
`Source\Support\ImageMetadata::strip()` decodifica a imagem no GD, gira pela orientação do EXIF e regrava no mesmo
arquivo e formato (JPEG e WebP com qualidade 90, PNG mantendo a transparência). Isso tira EXIF, XMP e os textos do PNG.
O `Upload` chama isso em toda imagem antes de gravar; imagem que o GD não lê é recusada. GIF fica como veio (não tem
EXIF e regravar perderia a animação). Teto de 50 milhões de pixels, o mesmo das fotos de vistoria.

A regra da orientação passa a ser uma só (`ImageMetadata::angle`), usada também pelas fotos de vistoria e do morador.

## Consequências
- Testes: `ImageMetadataTest` (JPEG com EXIF marcado e orientação 6 sai sem o EXIF e em pé; PNG sem o `tEXt` e com
  transparência; GIF intacto; imagem quebrada recusada; o `Upload::image` grava a cópia limpa; o teste falha sem a chamada).
- As imagens já gravadas antes desta mudança continuam como estavam. Na produção a pasta começa vazia; se as imagens
  antigas do site forem levadas (`tools/migrate-legacy-images.sh`), passar por um comando de limpeza antes.
