-- Reverte 20261116_1200 (ADR 0324): tira a permissão talk.access dos papéis e do catálogo.
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id WHERE p.slug = 'talk.access';
DELETE FROM `access_permissions` WHERE `slug` = 'talk.access';
