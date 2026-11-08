-- Permissões dos cadastros do ERP sobre o núcleo access_* existente (negam sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar proprietários e inquilinos','erp.residents.view','ERP Cadastros','Consultar o cadastro de proprietários e inquilinos dos condomínios do seu escopo'),
('Gerenciar proprietários e inquilinos','erp.residents.manage','ERP Cadastros','Cadastrar, alterar e excluir proprietários e inquilinos'),
('Visualizar síndico e conselho','erp.board.view','ERP Cadastros','Consultar o cadastro de síndico e conselho dos condomínios do seu escopo'),
('Gerenciar síndico e conselho','erp.board.manage','ERP Cadastros','Cadastrar, alterar e excluir síndico e conselho'),
('Visualizar funcionários','erp.employees.view','ERP Cadastros','Consultar o cadastro de funcionários dos condomínios do seu escopo'),
('Gerenciar funcionários','erp.employees.manage','ERP Cadastros','Cadastrar, alterar e excluir funcionários'),
('Visualizar fornecedores','erp.suppliers.view','ERP Cadastros','Consultar o cadastro de fornecedores dos condomínios do seu escopo'),
('Gerenciar fornecedores','erp.suppliers.manage','ERP Cadastros','Cadastrar, alterar e excluir fornecedores'),
('Visualizar advogados','erp.lawyers.view','ERP Cadastros','Consultar o cadastro de advogados dos condomínios do seu escopo'),
('Gerenciar advogados','erp.lawyers.manage','ERP Cadastros','Cadastrar, alterar e excluir advogados'),
('Visualizar veículos','erp.vehicles.view','ERP Cadastros','Consultar o cadastro de veículos dos condomínios do seu escopo'),
('Gerenciar veículos','erp.vehicles.manage','ERP Cadastros','Cadastrar, alterar e excluir veículos'),
('Visualizar visitantes','erp.visitors.view','ERP Cadastros','Consultar o cadastro de visitantes dos condomínios do seu escopo'),
('Gerenciar visitantes','erp.visitors.manage','ERP Cadastros','Cadastrar, alterar e excluir visitantes'),
('Visualizar animais de estimação','erp.pets.view','ERP Cadastros','Consultar o cadastro de animais de estimação dos condomínios do seu escopo'),
('Gerenciar animais de estimação','erp.pets.manage','ERP Cadastros','Cadastrar, alterar e excluir animais de estimação'),
('Visualizar contatos úteis','erp.contacts.view','ERP Cadastros','Consultar o cadastro de contatos úteis dos condomínios do seu escopo'),
('Gerenciar contatos úteis','erp.contacts.manage','ERP Cadastros','Cadastrar, alterar e excluir contatos úteis');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin')
  AND p.slug IN ('erp.residents.view','erp.residents.manage','erp.board.view','erp.board.manage','erp.employees.view','erp.employees.manage','erp.suppliers.view','erp.suppliers.manage','erp.lawyers.view','erp.lawyers.manage','erp.vehicles.view','erp.vehicles.manage','erp.visitors.view','erp.visitors.manage','erp.pets.view','erp.pets.manage','erp.contacts.view','erp.contacts.manage');
