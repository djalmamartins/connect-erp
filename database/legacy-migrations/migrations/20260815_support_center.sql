-- MovesOS: Central de Ajuda pública e gerenciável pelo Studio.
CREATE TABLE IF NOT EXISTS `support_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `uri` varchar(140) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(80) NOT NULL DEFAULT 'help-circle-outline',
  `position` int unsigned NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_support_category_uri` (`uri`), KEY `idx_support_category_status` (`status`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_articles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `author_id` int unsigned DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `uri` varchar(190) NOT NULL,
  `summary` varchar(320) NOT NULL,
  `content` longtext NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `views` int unsigned NOT NULL DEFAULT 0,
  `helpful_yes` int unsigned NOT NULL DEFAULT 0,
  `helpful_no` int unsigned NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_support_article_uri` (`uri`),
  KEY `idx_support_article_category` (`category_id`,`status`,`published_at`),
  CONSTRAINT `fk_support_article_category` FOREIGN KEY (`category_id`) REFERENCES `support_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_support_article_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_article_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int unsigned NOT NULL,
  `vote` enum('yes','no') NOT NULL,
  `visitor_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_support_vote` (`article_id`,`visitor_hash`),
  CONSTRAINT `fk_support_vote_article` FOREIGN KEY (`article_id`) REFERENCES `support_articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `support_categories` (`title`,`uri`,`description`,`icon`,`position`) VALUES
('Primeiros passos','primeiros-passos','Configuração inicial e primeiros acessos ao sistema.','rocket-outline',1),
('Conta e acesso','conta-e-acesso','Login, senha, usuários e permissões.','person-circle-outline',2),
('Financeiro e boletos','financeiro-e-boletos','Boletos, faturas, pagamentos e prestação de contas.','wallet-outline',3),
('Comunicação','comunicacao','Avisos, notificações, e-mails e relacionamento.','megaphone-outline',4),
('Condomínios','condominios','Cadastros, unidades e rotinas do condomínio.','business-outline',5),
('Suporte e segurança','suporte-e-seguranca','Ajuda, privacidade e boas práticas de segurança.','shield-checkmark-outline',6)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`description`=VALUES(`description`),`icon`=VALUES(`icon`),`position`=VALUES(`position`);

INSERT INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Gerenciar central de ajuda','support.manage','Conteúdo','Criar categorias e matérias da Central de Ajuda')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`);
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r CROSS JOIN access_permissions p WHERE p.slug='support.manage' AND r.slug IN ('developer','super_admin','client_admin','manager');

INSERT INTO `support_articles` (`category_id`,`author_id`,`title`,`uri`,`summary`,`content`,`status`,`published_at`)
SELECT c.id,1,'Como cadastrar uma comunicação','como-cadastrar-uma-comunicacao','Aprenda a enviar notificações pelo sistema, por e-mail ou pelos dois canais e programe o melhor horário.',
'<h2>Crie uma nova comunicação</h2><p>No MovesOS, acesse <strong>Notificações</strong> e localize o quadro <strong>Enviar comunicação</strong>.</p><ol><li>Informe um título curto, que também será o assunto do e-mail.</li><li>Escreva uma mensagem clara e objetiva.</li><li>Escolha os destinatários: todos, administradores, master ou uma pessoa.</li><li>Selecione o canal: sistema, e-mail ou ambos.</li><li>Defina a prioridade e acrescente um link interno quando houver uma tela relacionada.</li><li>Para enviar depois, escolha a data em <strong>Agendar para</strong>.</li></ol><h2>Acompanhe o envio</h2><p>As notificações aparecem no histórico. Os e-mails entram na fila de envio, onde você pode acompanhar tentativas, falhas, cancelamentos e reenvios.</p><p>Se um envio falhar, o MovesOS tentará novamente automaticamente antes de marcá-lo como falha definitiva.</p>',
'published',NOW() FROM support_categories c WHERE c.uri='comunicacao'
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`summary`=VALUES(`summary`),`content`=VALUES(`content`),`status`='published';
