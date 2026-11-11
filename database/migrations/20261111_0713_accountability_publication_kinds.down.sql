-- Reverte 20261111_0713: volta a chave única de 20261111_0712 e tira o tipo. As publicações anuais continuam na
-- tabela (competence AAAA não colide com AAAA-MM), mas sem o tipo; apague-as à mão se não forem mais necessárias.
ALTER TABLE `erp_accountability_publications`
  DROP INDEX IF EXISTS `uq_erp_accountability_publications_kind_version`,
  DROP INDEX IF EXISTS `idx_erp_accountability_publications_kind`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_erp_accountability_publications_version` (`condominium_id`, `competence`, `version`);

ALTER TABLE `erp_accountability_publications`
  DROP COLUMN IF EXISTS `kind`;
