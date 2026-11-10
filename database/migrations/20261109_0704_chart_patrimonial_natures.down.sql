-- Reverte 20261109_0704. Remove as contas patrimoniais do plano padrão criadas pela migration, desde que
-- nenhuma tenha saldo inicial (AP-161), e volta a natureza para receita/despesa. Se ainda houver conta
-- patrimonial (do condomínio ou com saldo inicial), o MODIFY falha, de propósito: não apagamos dado do usuário.
-- Remova ou reclassifique essas contas antes.
DELETE FROM `erp_chart_accounts`
WHERE `condominium_id` IS NULL AND `nature` IN ('asset', 'liability', 'equity')
  AND `code` IN ('7', '7.1', '7.2', '8', '8.1', '8.2', '8.3', '9', '9.1', '9.2')
  AND `id` NOT IN (SELECT o.`chart_account_id` FROM `erp_chart_account_openings` o);

ALTER TABLE `erp_chart_accounts`
  MODIFY `nature` enum('receita','despesa') NOT NULL;
