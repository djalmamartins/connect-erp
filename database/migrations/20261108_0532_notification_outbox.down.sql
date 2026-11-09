-- Reverte 20261108_0532: remove a outbox (eventos pendentes se perdem; rode só antes de haver fila).
DROP TABLE IF EXISTS `erp_notification_outbox`;
