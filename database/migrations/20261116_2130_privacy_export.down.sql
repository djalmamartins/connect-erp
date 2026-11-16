-- Reverte 20261116_2130 (ADR 0328): tira a permissão erp.privacy.export dos papéis, das exceções e do catálogo.
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id WHERE p.slug = 'erp.privacy.export';
DELETE o FROM `access_user_overrides` o JOIN `access_permissions` p ON p.id = o.permission_id WHERE p.slug = 'erp.privacy.export';
DELETE FROM `access_permissions` WHERE `slug` = 'erp.privacy.export';
