-- proposals.manage não é removida: pertence ao módulo de propostas
-- (components/proposals/database/001) e pode ter existido antes desta migration.
-- Vínculos em access_role_permissions e access_user_overrides caem por ON DELETE CASCADE.
DELETE FROM `access_permissions` WHERE `slug` = 'site.access';
