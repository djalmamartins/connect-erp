-- Publicação da prestação de contas anual (ADR 0183): a mesma tabela das publicações mensais (20261111_0712) ganha
-- o tipo. monthly = pacote do mês (competence AAAA-MM); annual = pacote do ano (competence AAAA). As versões são
-- contadas por condomínio, tipo e competência. As linhas que já existem ficam como monthly. Idempotente.
ALTER TABLE `erp_accountability_publications`
  ADD COLUMN IF NOT EXISTS `kind` enum('monthly','annual') NOT NULL DEFAULT 'monthly' COMMENT 'ADR 0183' AFTER `condominium_id`;

ALTER TABLE `erp_accountability_publications`
  DROP INDEX IF EXISTS `uq_erp_accountability_publications_version`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_erp_accountability_publications_kind_version` (`condominium_id`, `kind`, `competence`, `version`),
  ADD KEY IF NOT EXISTS `idx_erp_accountability_publications_kind` (`condominium_id`, `kind`, `competence`, `status`);
