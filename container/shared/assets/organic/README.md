# Organic V2 (distribuição compartilhada)

Distribuição oficial do Organic V2 consumida pelos ambientes do Connect.
Fica em `container/shared/assets/organic/` porque é usada por mais de um
ambiente (WEB, Suporte, App e ERP). O código-fonte permanece em
`../organic-v2`; não edite os bundles gerados diretamente aqui.

Conteúdo:

- `organic.min.css`, `organic.global.min.js`: bundles carregados pelos layouts.
- `organic.css`, `organic.js`, `organic.min.js`, `organic.global.js`: demais saídas do build.
- `compat-v1.css`, `compat-v1.js`: adaptadores temporários para consumidores legados.
- `fonts/`: fontes referenciadas por `organic.min.css` (caminho relativo).
- `brand/`, `mascot/`: identidade visual do Organic.

O editor (`organic-editor`) vive no tema do Studio, em
`container/cms/studio/v1/assets/vendor/organic-editor/`.

Para reconstruir e sincronizar:

```bash
cd ../organic-v2
npm ci
npm test
npm run build

cd ../connect
php service/commands/sync-organic-v2.php ../organic-v2
```
