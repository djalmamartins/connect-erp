-- Reverte 20261109_0705: remove as permissões das telas AP-149 e AP-150. As concessões e exceções caem
-- junto (ON DELETE CASCADE). O acesso a condomínios dado pela tela continua, porque é dado do usuário.
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.users.view','erp.users.manage','erp.permissions.view','erp.permissions.manage');
