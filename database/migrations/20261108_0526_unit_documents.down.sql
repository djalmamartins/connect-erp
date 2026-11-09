-- Reverte 20261108_0526: remove os documentos emitidos e as permissões.
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.unit_documents.view','erp.unit_documents.manage');
DROP TABLE IF EXISTS `erp_unit_documents`;
