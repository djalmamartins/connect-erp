# 0047 — Disco Virtual (AP-122): arquivos do condomínio fora da pasta pública

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O Disco Virtual (AP-122) guarda atas, contratos, plantas e notas fiscais de cada condomínio. A
coordenação orientou: upload fora da pasta pública, tipos e tamanho limitados, e download só com
permissão e escopo. O mesmo cuidado já vale para as fotos das vistorias (ADR 0015).

## Decisão

- **Guarda privada:**
  - os arquivos ficam em `storage/private/documents/{condomínio}/{ano}/{aleatório}.{ext}`, fora do Git
    (`storage/private/` no `.gitignore`), com `.htaccess` "Require all denied" como defesa extra;
  - o nome no disco é gerado pelo sistema (128 bits aleatórios); o nome enviado é limpo e só aparece
    na listagem e no download;
  - a leitura (`absolute`) só aceita o formato gerado, então não há como sair da pasta.
- **Tipos e tamanho:**
  - o tipo é conferido pelo conteúdo (`finfo`), numa lista fechada: PDF, JPG, PNG, WebP, DOCX, XLSX,
    ODT, ODS, CSV e TXT;
  - a extensão precisa bater com o tipo: PHP com nome de PDF, HTML, SVG e ZIP disfarçado de DOCX são
    recusados;
  - no máximo 20 MB;
  - em produção só vale arquivo de upload HTTP (`is_uploaded_file`/`move_uploaded_file`); o modo de
    arquivo local existe só para testes e importações.
- **Duplicidade:** o mesmo conteúdo (sha256) na mesma pasta do condomínio é recusado (409).
- **Pastas:** atas, contratos, plantas, notas fiscais e outros.
- **Download:**
  - `GET /api/v1/documents/{id}/download` exige `erp.documents.view` e escopo do condomínio (404 fora
    dele);
  - o arquivo vai como anexo, com `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` e
    `no-store`.
- **Exclusão lógica**, auditada: o registro sai da lista, mas o arquivo continua guardado.
- **Permissões** `erp.documents.view` e `erp.documents.manage` (migration 0528).

## Consequências

- A frente de layout monta a tela AP-122 (pastas, enviar e baixar) sobre a API. O envio é multipart,
  com o token CSRF no cabeçalho, como nas demais APIs.
- O backup do servidor precisa incluir `storage/private/documents`.
- Ficam para depois: antivírus no upload, versões do mesmo documento e cota por condomínio.
- Próximos de Documentos: Avisos de Recebimento (AP-126), Cópias de Cheques (AP-127), Empenhos
  (AP-128) e Malotes (AP-129).
