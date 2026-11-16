-- Desfaz 20261116_0410: tira o índice e a marca do lembrete (os contatos ficam como estavam).
ALTER TABLE `erp_collection_contacts`
  DROP KEY IF EXISTS `idx_erp_collection_contacts_next_action`,
  DROP COLUMN IF EXISTS `reminded_at`;
