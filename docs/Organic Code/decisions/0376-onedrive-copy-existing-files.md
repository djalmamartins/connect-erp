# ADR 0376 — Copiar os arquivos existentes para o OneDrive antes da troca

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADRs 0370–0375, HOSPEDAGEM.md §10

## Contexto

Trocar `DOCUMENT_STORAGE` para `onedrive` sem levar os arquivos que já estão no servidor deixaria documentos, fotos de
vistoria e fotos dos moradores indisponíveis.

## Decisão

`php tools/hosting/onedrive-copy.php [--dry-run]`:

- copia `storage/private/documents`, `inspections` e `resident-photos` para `ONEDRIVE_FOLDER/{área}`, com a mesma
  árvore de pastas, usando o mesmo `OneDriveDocumentStore` do sistema (`DocumentStores::oneDrive`, que funciona mesmo
  com `DOCUMENT_STORAGE=local`, porque a cópia vem antes da troca);
- só arquivos comuns: sem links, sem `.htaccess` e sem arquivos ocultos;
- anota o que subiu em `storage/temp/onedrive-copy-{área}.done`; rodar de novo só envia o que faltou ou falhou;
- não apaga nada da pasta local (ela continua como cópia até alguém decidir apagar);
- saída 0 tudo copiado, 1 algum falhou (rodar de novo), 2 chaves do OneDrive faltando.

## Testes

`StorageCopyTest`: lista só arquivos comuns; `--dry-run` não envia; falha fica de fora da anotação e sobe na segunda
rodada; pasta local intacta; OneDrive da área com modo local e recusa com chave faltando; o script sai com 2 sem as chaves.
