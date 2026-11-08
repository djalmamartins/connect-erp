-- Permissões de áreas comuns, áreas vinculáveis e patrimônio (negam sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar áreas comuns','erp.common_areas.view','ERP Condomínios','Consultar as áreas comuns dos condomínios do seu escopo'),
('Gerenciar áreas comuns','erp.common_areas.manage','ERP Condomínios','Cadastrar, alterar e excluir áreas comuns'),
('Visualizar áreas vinculáveis','erp.linkable_areas.view','ERP Condomínios','Consultar vagas, depósitos e boxes dos condomínios do seu escopo'),
('Gerenciar áreas vinculáveis','erp.linkable_areas.manage','ERP Condomínios','Cadastrar, alterar e excluir vagas, depósitos e boxes'),
('Visualizar patrimônio','erp.assets.view','ERP Condomínios','Consultar os bens dos condomínios do seu escopo'),
('Gerenciar patrimônio','erp.assets.manage','ERP Condomínios','Cadastrar, alterar e baixar bens do patrimônio');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin')
  AND p.slug IN ('erp.common_areas.view','erp.common_areas.manage','erp.linkable_areas.view','erp.linkable_areas.manage','erp.assets.view','erp.assets.manage');
