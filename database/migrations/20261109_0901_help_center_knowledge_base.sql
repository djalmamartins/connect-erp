-- Base de conhecimento do ERP (ADR 0141): os artigos são arquivos versionados em
-- container/web/support/v1/kb e chegam à Central de Ajuda pública (/suporte) por
-- `php tools/help.php sync`. kb_key marca o que veio da base (o sync só altera essas linhas; as
-- matérias escritas no Help Desk continuam intocadas), kb_hash evita regravar o que não mudou e
-- kb_meta guarda o que a página do artigo usa além do texto (ícone, rota, índice, relacionados).
ALTER TABLE support_categories
    ADD COLUMN IF NOT EXISTS kb_key VARCHAR(80) NULL AFTER status,
    ADD UNIQUE INDEX IF NOT EXISTS uq_support_category_kb_key (kb_key);

ALTER TABLE support_articles
    ADD COLUMN IF NOT EXISTS kb_key VARCHAR(190) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS kb_hash CHAR(64) NULL AFTER kb_key,
    ADD COLUMN IF NOT EXISTS kb_meta LONGTEXT NULL AFTER kb_hash,
    ADD UNIQUE INDEX IF NOT EXISTS uq_support_article_kb_key (kb_key);
