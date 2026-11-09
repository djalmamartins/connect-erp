-- Reverte 20261108_0517: remove os eventos e os arquivos de retorno (os boletos continuam).
DROP TABLE IF EXISTS `erp_bank_events`;
DROP TABLE IF EXISTS `erp_bank_return_files`;
