-- Revisão de 15/11, etapa 1 (ADR 0310): a unidade ganha a marca "Unidade vazia" (sem ninguém morando), que aparece na
-- ficha e no painel da unidade e vira filtro "Ocupação" (ocupadas/vazias) na lista de unidades. Marcada à mão pela equipe.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_units`
  ADD COLUMN IF NOT EXISTS `is_vacant` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Unidade vazia, sem morador (marcada pela equipe)' AFTER `private_area`;
