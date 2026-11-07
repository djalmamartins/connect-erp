# 0005 — Imagens do legado ficam no storage, fora do Git

Data: 2026-11-07 · Status: aceito

## Contexto

O site WEB e o conteúdo do banco (artigos em `posts.cover`, slides em `slides.cover`,
depoimentos em `brief.cover`, fotos de usuários) gravam caminhos relativos como
`images/2021/10/obras-no-condominio.png`. O helper `image()` resolve esses caminhos
em `storage/images/` (`CONF_UPLOAD_DIR`/`CONF_UPLOAD_IMAGE_DIR`) e gera miniaturas
em `storage/images/cache/` via `Source\Support\Thumb`. No Connect o storage estava
vazio, então os cards de artigos e depoimentos caíam no avatar padrão.

As imagens dos temas (`container/web/default/v1/assets`, `container/cms/studio/v1/assets`)
já estavam completas no repositório e não foram alteradas.

## Decisão

- Os uploads do legado são copiados para `storage/images/` com
  `tools/migrate-legacy-images.sh <public_html> <dump.sql>`. O diretório é ignorado pelo Git.
- Só entram os arquivos ainda referenciados pelo dump (29 de 57 em 2026-11-07, ~41 MB).
  Fotos pessoais antigas sem referência ficaram de fora.
- O cache do legado não é copiado: as miniaturas são regeneradas sob demanda.
- Não foram migrados: anexos de tickets públicos (`public-ticket-*`), nem
  `storage/files` (propostas em PDF e documentos), por conterem dados pessoais/comerciais
  sem uso no WEB. Migrar quando o módulo correspondente exigir.
- As referências a `storage/images/cache/...` e `/images/share.jpg` no dump existem só
  em `app_log` (registros de 404 do legado) e não precisam ser resolvidas.

## Consequências

Um ambiente novo precisa rodar o script uma vez com o legado disponível. Em produção,
as imagens devem ir para o storage persistente do servidor, nunca para o repositório.
