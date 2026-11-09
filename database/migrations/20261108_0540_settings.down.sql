-- Reverte 20261108_0540: remove os parâmetros gravados (o sistema volta aos padrões do código), o saldo
-- inicial por centro de custo e a previsão orçamentária.
DROP TABLE IF EXISTS `erp_taxes`;
DROP TABLE IF EXISTS `erp_banners`;
DROP TABLE IF EXISTS `erp_budget_lines`;
DROP TABLE IF EXISTS `erp_cost_center_openings`;
DROP TABLE IF EXISTS `erp_settings`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.settings.view','erp.settings.manage');
