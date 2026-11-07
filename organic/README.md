# Organic V2 no Moves

Esta pasta contém a distribuição oficial do Organic V2 consumida pelo Moves.
O código-fonte permanece em `../organic-v2`; não edite os bundles gerados
diretamente neste diretório.

Para reconstruir e sincronizar a distribuição:

```bash
cd ../organic-v2
npm ci
npm test
npm run build

cd ../erp
php service/commands/sync-organic-v2.php ../organic-v2
```

Os adaptadores `compat-v1.css` e `compat-v1.js` são temporários e devem ser
removidos somente após todos os consumidores legados terem sido migrados.
Bibliotecas de terceiros usadas por aplicações antigas ficam separadas em
`container/shared/assets/vendor/`.
