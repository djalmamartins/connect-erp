-- Reverte 20261108_0528: remove o registro dos arquivos e as permissões. Os arquivos em
-- storage/private/documents NÃO são apagados por esta migration (remoção manual, se for o caso).
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.documents.view','erp.documents.manage');
DROP TABLE IF EXISTS `erp_documents`;
