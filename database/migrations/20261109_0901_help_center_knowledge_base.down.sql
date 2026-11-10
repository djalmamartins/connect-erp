-- Reversão do ADR 0141: as matérias da base continuam publicadas como matérias comuns.
ALTER TABLE support_articles
    DROP INDEX IF EXISTS uq_support_article_kb_key,
    DROP COLUMN IF EXISTS kb_meta,
    DROP COLUMN IF EXISTS kb_hash,
    DROP COLUMN IF EXISTS kb_key;

ALTER TABLE support_categories
    DROP INDEX IF EXISTS uq_support_category_kb_key,
    DROP COLUMN IF EXISTS kb_key;
