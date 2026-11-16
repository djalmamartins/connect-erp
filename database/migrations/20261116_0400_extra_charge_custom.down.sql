-- Volta o cálculo custom para fixed (o valor de cada conta continua o mesmo) antes de tirar a opção.
UPDATE `erp_extra_charges` SET `method` = 'fixed' WHERE `method` = 'custom';
ALTER TABLE `erp_extra_charges`
  MODIFY `method` enum('fixed','equal','fraction') NOT NULL COMMENT 'fixed = valor por unidade; equal/fraction = total rateado';
