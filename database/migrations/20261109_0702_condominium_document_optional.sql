-- CNPJ do condomínio opcional (ADR 0162). A coluna já aceita NULL desde a 20261107_0002; aqui só troca o
-- CNPJ vazio ('') por NULL, para que dois condomínios sem CNPJ não colidam no índice único
-- uq_condominiums_document. Idempotente (a suíte reaplica as migrations a cada execução).
UPDATE `condominiums` SET `document` = NULL WHERE `document` = '';
