-- Reverte 20261122_1200_ticket_protocols (ADR 0637). Novos chamados deixam de ter sequência.
-- A coluna protocol continua com 20 caracteres: voltar para char(12) cortaria os protocolos SAC/OCO já dados.
DROP TABLE IF EXISTS `studio_support_protocol_sequences`;
