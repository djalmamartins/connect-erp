-- Reversão da ADR 0650: as propostas cadastradas no ERP continuam na tabela, só perdem a origem e quem cadastrou.
-- proposal_responses e as colunas de anexo do mail_queue não são removidas: já existiam antes desta migration.
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `created_by`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `origin`;
