-- Balanço Patrimonial (AP-081, ADR 0167): naturezas patrimoniais no plano de contas e as contas
-- patrimoniais do plano padrão (condominium_id NULL).
--
-- Os códigos 1 e 2 já são usados por Receitas e Despesas no plano padrão. Por isso as contas patrimoniais
-- ficam em 7 (Ativo), 8 (Passivo) e 9 (Patrimônio Líquido). Lançamento continua só em receita ou despesa
-- (a natureza do lançamento precisa ser a da conta). As contas 7.1, 7.2, 8.1, 9.1 e 9.2 são calculadas
-- pelo balanço; as demais (fundos) recebem saldo inicial (AP-161).
--
-- Idempotente: o MODIFY pode ser reaplicado. Cada conta só entra se o código ainda não existir no plano
-- padrão, e a filha só entra se a mãe for patrimonial da mesma natureza. Assim não há duplicata nem conta
-- pendurada em mãe de receita.
ALTER TABLE `erp_chart_accounts`
  MODIFY `nature` enum('receita','despesa','asset','liability','equity') NOT NULL COMMENT 'asset/liability/equity = contas patrimoniais (ADR 0167)';

INSERT INTO `erp_chart_accounts` (`condominium_id`, `code`, `name`, `nature`, `kind`, `notes`)
SELECT NULL, s.code, s.name, s.nature, 'sintetica', 'Plano padrão: grupo do Balanço Patrimonial (ADR 0167).'
FROM (SELECT '7' AS code, 'Ativo' AS name, 'asset' AS nature
      UNION ALL SELECT '8', 'Passivo', 'liability'
      UNION ALL SELECT '9', 'Patrimônio Líquido', 'equity') s
WHERE NOT EXISTS (SELECT 1 FROM `erp_chart_accounts` x WHERE x.`condominium_id` IS NULL AND x.`code` = s.code AND x.`deleted_at` IS NULL);

INSERT INTO `erp_chart_accounts` (`condominium_id`, `code`, `name`, `nature`, `kind`, `notes`)
SELECT NULL, s.code, s.name, s.nature, s.kind, s.notes
FROM (SELECT '7.1' AS code, 'Disponível' AS name, 'asset' AS nature, 'sintetica' AS kind, 'Calculada: saldos das contas bancárias.' AS notes, '7' AS parent
      UNION ALL SELECT '7.2', 'Contas a receber', 'asset', 'analitica', 'Calculada: receitas em aberto (vencidas e a vencer).', '7'
      UNION ALL SELECT '8.1', 'Contas a pagar', 'liability', 'analitica', 'Calculada: despesas lançadas e não pagas.', '8'
      UNION ALL SELECT '8.2', 'Fundo de reserva', 'liability', 'analitica', 'Saldo inicial (AP-161).', '8'
      UNION ALL SELECT '8.3', 'Outros fundos', 'liability', 'analitica', 'Saldo inicial (AP-161).', '8'
      UNION ALL SELECT '9.1', 'Resultados acumulados', 'equity', 'analitica', 'Saldo inicial (AP-161) + resultado dos anos anteriores.', '9'
      UNION ALL SELECT '9.2', 'Resultado do exercício', 'equity', 'analitica', 'Calculada: resultado de 1º de janeiro até a data.', '9') s
WHERE NOT EXISTS (SELECT 1 FROM `erp_chart_accounts` x WHERE x.`condominium_id` IS NULL AND x.`code` = s.code AND x.`deleted_at` IS NULL)
  AND EXISTS (SELECT 1 FROM `erp_chart_accounts` p WHERE p.`condominium_id` IS NULL AND p.`code` = s.parent AND p.`nature` = s.nature
    AND p.`kind` = 'sintetica' AND p.`deleted_at` IS NULL);
