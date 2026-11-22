-- Protocolo dos chamados no formato do Connect Talk (ADR 0636). Idempotente.
-- SAC + AAAAMMDD + sequência do dia (4 dígitos) para chamados; OCO + AAAAMMDD + sequência para ocorrências.
-- Os protocolos antigos (CH + aammdd + 4 hex e OC + id) ficam como estão: já foram enviados por e-mail e continuam valendo.
ALTER TABLE `studio_support_tickets`
    MODIFY `protocol` varchar(20) NOT NULL COMMENT 'SAC/OCO + AAAAMMDD + sequência do dia (ADR 0636); antigos CH/OC preservados';

-- Último número dado por prefixo e dia. A linha fica travada até o fim da transação do chamado,
-- então dois chamados abertos ao mesmo tempo nunca recebem o mesmo número.
CREATE TABLE IF NOT EXISTS `studio_support_protocol_sequences` (
  `prefix` char(3) NOT NULL,
  `day` date NOT NULL,
  `last_number` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`prefix`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sequência diária dos protocolos SAC/OCO (ADR 0636)';
