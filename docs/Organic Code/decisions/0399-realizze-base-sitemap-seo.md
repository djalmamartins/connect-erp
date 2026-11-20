# 0399 — Base do Realizze conferida: sitemap.xml, dados da empresa para o Google e miniatura sem derrubar a página

- **Status:** aceito
- **Data:** 2026-11-20
- **Relacionados:** 0004 (assets compartilhados), 0365 (telas internas fora do Google), 0389 (imagens sem EXIF);
  análise completa em `docs/architecture/REALIZZE_COMPARISON.md`.

## Contexto

Em 20/11/2026 o proprietário enviou o código do Realizze (PHP 7, base CafeControl/coffeecode) e pediu
para trazer ao Connect o que ele tem de bom: crop, model, view, SEO, compress, storage, middleware,
paginação e rotas, "para não precisar fazer SEO".

A comparação arquivo por arquivo (CONFIRMADO) mostrou que o núcleo do Connect **já é o Realizze**,
reescrito para PHP 8.2: `source/Core` (Model, View, Session, Connect, Controller) e `source/Support`
(Seo, Thumb, Upload, Pager, Message, Email) têm as mesmas classes e os mesmos métodos, com acréscimos
(auditoria, log, fuso do banco, CSRF forte, sessão segura, validação real do tipo de arquivo, EXIF).
Os pacotes coffeecode foram trocados pelos `movescode/*` (router, view, compress, storage, cropper,
seo, pager), que cobrem tudo que o Realizze usava e somam middleware, subdomínio, JSON-LD e WebP.
Todos os helpers do Realizze existem no Connect. Só ficou de fora `AppEnterprises`, que é cadastro
de empresas clientes (SaaS) e é proibido pela ADR-002.

Três lacunas reais apareceram:

1. **Sem mapa do site.** Nem o Realizze nem o Connect tinham `sitemap.xml`; o `robots.txt` não apontava um.
2. **JSON-LD sem uso.** O `movescode/seo` sabe gerar `Organization`/`LocalBusiness`, mas nenhuma página usava.
3. **Imagem quebrada derrubava a página.** O Cropper do Realizze devolvia texto de erro; o novo lança
   exceção, e o helper `image()` não tratava: uma imagem corrompida no Studio quebrava o site.

## Decisão

- `GET /sitemap.xml` (site) gerado por `Source\Support\Sitemap`: páginas fixas, artigos publicados
  (sem rascunho nem agendado), categorias com artigo e a Central de Ajuda publicada. Uma parte que
  falhar (tabela ausente) é registrada no log e não derruba o resto.
- `robots.txt` ganha `Sitemap: https://www.connectcondominios.com.br/sitemap.xml` (domínio de produção).
- `Seo::business()` monta o JSON-LD com nome, endereço, telefone, logo e redes sociais de
  Configurações do Studio: `LocalBusiness` com endereço completo, `Organization` sem endereço. Usado na
  página inicial. Ajuda também no item do checklist legal "CNPJ, endereço e contato no site" (o CNPJ
  ainda não existe em Configurações; fica para a thread da configuração geral).
- `image()` captura a exceção do Cropper, registra no log (`thumb_failed`) e mostra o avatar padrão.

## Consequências

- Nada muda no banco, na API ou nas telas do ERP; nenhuma página nova (sem artigo novo na Central).
- No Google Search Console, enviar o `sitemap.xml` depois que o domínio estiver no ar.
- Testes: `SitemapSeoTest` (XML válido, escape, sem duplicata, rota e robots, JSON-LD) e
  `ThumbFallbackTest::testCorruptedImageFallsBackToAvatarInsteadOfBreakingThePage`.
