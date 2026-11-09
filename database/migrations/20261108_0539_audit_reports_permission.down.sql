-- Reverte 20261108_0539: remove a permissão dos relatórios administrativos.
DELETE FROM `access_permissions` WHERE `slug` = 'erp.audit.view';
