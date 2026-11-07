ALTER TABLE `faq_questions`
  ADD COLUMN IF NOT EXISTS `support_link` varchar(255) DEFAULT NULL AFTER `response`;
