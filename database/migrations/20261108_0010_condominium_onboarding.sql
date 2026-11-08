-- Checklist de chegada do condomínio (ADR 0013). Catálogo configurável de itens
-- e situação de cada item por condomínio, com protocolo gerado quando há pendência.
CREATE TABLE IF NOT EXISTS `onboarding_item_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `category` enum('documentacao','seguranca','manutencao','seguros','financeiro','pessoas') NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `default_due_days` smallint unsigned NOT NULL DEFAULT 30 COMMENT 'Prazo padrão do protocolo, em dias',
  `renewal_months` smallint unsigned DEFAULT NULL COMMENT 'Periodicidade de renovação; NULL = não vence',
  `position` smallint unsigned NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_onboarding_item_templates_code` (`code`),
  KEY `idx_onboarding_item_templates_active` (`active`, `category`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `condominium_onboarding_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `template_id` int unsigned DEFAULT NULL COMMENT 'NULL = item avulso do condomínio',
  `category` enum('documentacao','seguranca','manutencao','seguros','financeiro','pessoas') NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` enum('pending','regular','action_required','in_progress','not_applicable') NOT NULL DEFAULT 'pending',
  `protocol` varchar(20) DEFAULT NULL,
  `due_date` date DEFAULT NULL COMMENT 'Prazo para regularizar',
  `expires_at` date DEFAULT NULL COMMENT 'Validade do documento/serviço quando regular',
  `notes` text DEFAULT NULL,
  `responsible_user_id` int unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coi_condominium_template` (`condominium_id`, `template_id`),
  UNIQUE KEY `uq_coi_protocol` (`protocol`),
  KEY `idx_coi_condominium_status` (`condominium_id`, `status`),
  KEY `idx_coi_expires` (`status`, `expires_at`),
  CONSTRAINT `fk_coi_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coi_template` FOREIGN KEY (`template_id`) REFERENCES `onboarding_item_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo inicial (INFERIDO: periodicidades de mercado, a confirmar pela Connect;
-- prazos legais variam por município/estado). Itens podem ser desativados ou ajustados.
INSERT IGNORE INTO `onboarding_item_templates` (`code`,`category`,`title`,`description`,`default_due_days`,`renewal_months`,`position`) VALUES
('cnpj','documentacao','CNPJ do condomínio','Verificar se o condomínio já tem CNPJ ativo na Receita Federal ou se é preciso abrir.',30,NULL,10),
('convencao','documentacao','Convenção registrada em cartório','Convenção do condomínio registrada no Cartório de Registro de Imóveis.',60,NULL,20),
('regimento','documentacao','Regimento interno','Regimento interno aprovado em assembleia e disponível aos condôminos.',60,NULL,30),
('ata_sindico','documentacao','Ata de eleição do síndico vigente','Ata da assembleia que elegeu o síndico atual, registrada quando exigido.',30,NULL,40),
('certificado_digital','documentacao','Certificado digital e-CNPJ','Certificado digital do condomínio para eSocial, DCTFWeb e bancos.',15,12,50),
('contrato_administracao','documentacao','Contrato de administração com a Connect','Contrato assinado e procuração do síndico para a Connect.',15,NULL,60),
('avcb','seguranca','AVCB / CLCB do Corpo de Bombeiros','Auto de Vistoria (ou Certificado de Licença) do Corpo de Bombeiros válido.',90,NULL,10),
('extintores','seguranca','Extintores: recarga e inspeção','Extintores dentro da validade, com selo do Inmetro e sinalização.',30,12,20),
('hidrantes','seguranca','Mangueiras e hidrantes: inspeção','Inspeção e teste das mangueiras e do sistema de hidrantes.',30,12,30),
('spda','seguranca','SPDA (para-raios): laudo','Laudo de inspeção do sistema de proteção contra descargas atmosféricas.',60,12,40),
('brigada','seguranca','Brigada de incêndio','Treinamento da brigada de incêndio dos funcionários.',60,12,50),
('elevadores','manutencao','Elevadores: contrato de manutenção','Contrato de manutenção e relatório de inspeção anual dos elevadores.',30,12,10),
('caixa_agua','manutencao','Limpeza da caixa d''água','Limpeza e higienização dos reservatórios, com certificado.',30,6,20),
('dedetizacao','manutencao','Dedetização e desratização','Controle de pragas com certificado da empresa responsável.',30,6,30),
('seguro_predial','seguros','Seguro obrigatório da edificação','Apólice de seguro contra incêndio e destruição (Código Civil, art. 1.346).',15,12,10),
('conta_bancaria','financeiro','Conta bancária em nome do condomínio','Conta corrente no CNPJ do condomínio para receber as cotas.',30,NULL,10),
('prestacao_contas','financeiro','Prestação de contas da gestão anterior','Saldos, extratos e documentos recebidos da administração anterior.',30,NULL,20),
('inadimplencia','financeiro','Levantamento de inadimplência','Lista de unidades em atraso e acordos vigentes.',30,NULL,30),
('certidoes','financeiro','Certidões negativas (Receita e FGTS)','Certidões de regularidade fiscal e do FGTS do condomínio.',30,6,40),
('funcionarios','pessoas','Funcionários registrados e eSocial em dia','Registro dos funcionários próprios e obrigações do eSocial.',30,NULL,10),
('laudos_trabalhistas','pessoas','PGR e PCMSO','Programa de Gerenciamento de Riscos e Programa de Controle Médico de Saúde Ocupacional.',60,12,20);

INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Configurar checklist de chegada','erp.onboarding.catalog','ERP','Alterar o catálogo de itens do checklist de chegada dos condomínios');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug = 'erp.onboarding.catalog';
