-- Feriados do estado e da cidade no prazo da prestação de contas (ADR 0306).
-- O 5º dia útil (ADR 0301) já pula os feriados nacionais e os dias sem expediente bancário. Aqui a equipe cadastra os
-- feriados estaduais (city_key vazio) e municipais (city_key = cidade normalizada, sem acento e em minúsculas) que
-- valem para os condomínios daquele lugar. year = 0 repete todo ano; year > 0 vale só naquele ano (ponto facultativo
-- decretado, por exemplo). Nada de tenant: o lugar vem do cadastro do condomínio (cidade e UF).
-- Idempotente.
CREATE TABLE IF NOT EXISTS `erp_local_holidays` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `state` char(2) NOT NULL COMMENT 'UF',
  `city` varchar(100) DEFAULT NULL COMMENT 'Cidade como escrita no cadastro; NULL = o estado todo',
  `city_key` varchar(100) NOT NULL DEFAULT '' COMMENT 'Cidade normalizada; vazio = o estado todo',
  `month` tinyint unsigned NOT NULL,
  `day` tinyint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL DEFAULT 0 COMMENT '0 = todo ano',
  `name` varchar(120) NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_local_holidays_day` (`state`, `city_key`, `year`, `month`, `day`),
  KEY `idx_erp_local_holidays_place` (`state`, `city_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
