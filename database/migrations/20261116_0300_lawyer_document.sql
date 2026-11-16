-- Revisão de 15/11, etapa 3 (ADR 0312): advogado com CPF ou CNPJ (escritório). O campo passa de 11 para 14 dígitos.
ALTER TABLE `erp_lawyers` MODIFY `document` varchar(14) DEFAULT NULL COMMENT 'CPF (11) ou CNPJ (14), só dígitos';
