-- Reverte 20261109_0712: remove as publicações da prestação de contas. Os lançamentos e os fechamentos do mês
-- não mudam; só se perde o registro do pacote entregue.
DROP TABLE IF EXISTS `erp_accountability_publications`;
