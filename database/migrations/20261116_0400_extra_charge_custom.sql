-- Revisão de 15/11, etapa 4 (ADR 0313): Nova Receita com o valor de cada unidade (tabela por unidade).
-- O cálculo custom guarda na origem que cada unidade recebeu um valor próprio.
ALTER TABLE `erp_extra_charges`
  MODIFY `method` enum('fixed','equal','fraction','custom') NOT NULL COMMENT 'fixed = valor por unidade, equal/fraction = total rateado, custom = valor de cada unidade';
