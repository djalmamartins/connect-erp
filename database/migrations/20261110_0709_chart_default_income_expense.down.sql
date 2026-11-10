-- Reverte 20261110_0709. Remove só as contas do plano padrão criadas por ela (nota do ADR 0152) que
-- ninguém usa. Conta usada fica, de propósito: não apagamos dado do usuário. Primeiro as analíticas sem
-- uso; depois as sintéticas que ficaram sem filhas.
DELETE FROM `erp_chart_accounts`
WHERE `condominium_id` IS NULL AND `kind` = 'analitica' AND `notes` = 'Plano padrão Connect (ADR 0152).'
  AND NOT EXISTS (SELECT 1 FROM `erp_entries` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_recurring_entries` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_invoice_types` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_agreements` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_extra_charges` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_budget_lines` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_chart_account_openings` u WHERE u.`chart_account_id` = `erp_chart_accounts`.`id`)
  AND NOT EXISTS (SELECT 1 FROM `erp_patrimonial_entries` u WHERE u.`debit_account_id` = `erp_chart_accounts`.`id` OR u.`credit_account_id` = `erp_chart_accounts`.`id`);

DELETE c FROM `erp_chart_accounts` c
LEFT JOIN `erp_chart_accounts` child ON child.`condominium_id` IS NULL AND child.`deleted_at` IS NULL AND child.`code` LIKE CONCAT(c.`code`, '.%')
WHERE c.`condominium_id` IS NULL AND c.`kind` = 'sintetica' AND c.`code` = '2.04' AND c.`notes` = 'Plano padrão Connect (ADR 0152).' AND child.`id` IS NULL;

DELETE c FROM `erp_chart_accounts` c
LEFT JOIN `erp_chart_accounts` child ON child.`condominium_id` IS NULL AND child.`deleted_at` IS NULL AND child.`code` LIKE CONCAT(c.`code`, '.%')
WHERE c.`condominium_id` IS NULL AND c.`kind` = 'sintetica' AND c.`code` IN ('1', '2') AND c.`notes` = 'Plano padrão Connect (ADR 0152).' AND child.`id` IS NULL;
