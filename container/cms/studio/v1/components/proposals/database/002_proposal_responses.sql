CREATE TABLE IF NOT EXISTS `proposal_responses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `template_type` ENUM('syndic','administrator') NOT NULL DEFAULT 'syndic',
  `subject` VARCHAR(255) NOT NULL,
  `introduction` TEXT NOT NULL,
  `scope` TEXT NOT NULL,
  `commercial_terms` TEXT NOT NULL,
  `payment_terms` VARCHAR(500) NULL,
  `valid_until` DATE NOT NULL,
  `notes` TEXT NULL,
  `pdf_path` VARCHAR(500) NULL,
  `status` ENUM('draft','generated','queued','sent','failed') NOT NULL DEFAULT 'draft',
  `queued_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proposal_responses_proposal` (`proposal_id`,`created_at`),
  KEY `idx_proposal_responses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `mail_queue`
  ADD COLUMN IF NOT EXISTS `attachments_json` LONGTEXT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `proposal_response_id` INT UNSIGNED NULL AFTER `notification_message_id`,
  ADD KEY IF NOT EXISTS `idx_mail_queue_proposal_response` (`proposal_response_id`);
