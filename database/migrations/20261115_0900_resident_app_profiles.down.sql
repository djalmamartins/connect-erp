-- Reverte 20261115_0900: o aplicativo volta a não ter foto nem boas-vindas. Os arquivos em
-- storage/private/resident-photos ficam no disco e podem ser apagados à mão.
DROP TABLE IF EXISTS `erp_resident_app_profiles`;
