-- Reverte 20261116_0300: só volta para 11 dígitos se não houver CNPJ gravado (senão o MODIFY falha e nada muda).
ALTER TABLE `erp_lawyers` MODIFY `document` char(11) DEFAULT NULL;
