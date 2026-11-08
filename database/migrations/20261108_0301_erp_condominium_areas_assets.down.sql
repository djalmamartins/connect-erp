-- Remove áreas e patrimônio do ERP (destrutivo: exige backup e aprovação).
DROP TABLE IF EXISTS `erp_assets`;
DROP TABLE IF EXISTS `erp_linkable_areas`;
DROP TABLE IF EXISTS `erp_common_areas`;
