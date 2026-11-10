-- Plano padrão de receitas e despesas do condomínio (ADR 0152).
--
-- Antes desta migration o plano padrão (condominium_id NULL) só tinha as contas patrimoniais 7/8/9 do
-- Balanço (ADR 0167). Num banco novo, o formulário de lançamento oferecia "8.1 Contas a pagar", que é
-- passivo e não recebe lançamento, e não havia nenhuma conta de despesa: salvar a despesa dava erro.
--
-- Idempotente e sem mexer no que já existe: cada conta só entra se o código ainda não existir no plano
-- padrão, e a filha só entra se a mãe existir, for sintética e tiver a mesma natureza (regra do motor de
-- cadastros). Quem já cadastrou o próprio 1 ou 2 fica com o seu.
INSERT INTO `erp_chart_accounts` (`condominium_id`, `code`, `name`, `nature`, `kind`, `notes`)
SELECT NULL, s.code, s.name, s.nature, 'sintetica', 'Plano padrão Connect (ADR 0152).'
FROM (SELECT '1' AS code, 'Receitas' AS name, 'receita' AS nature
      UNION ALL SELECT '2', 'Despesas', 'despesa') s
WHERE NOT EXISTS (SELECT 1 FROM `erp_chart_accounts` x WHERE x.`condominium_id` IS NULL AND x.`code` = s.code AND x.`deleted_at` IS NULL);

INSERT INTO `erp_chart_accounts` (`condominium_id`, `code`, `name`, `nature`, `kind`, `notes`)
SELECT NULL, s.code, s.name, s.nature, s.kind, 'Plano padrão Connect (ADR 0152).'
FROM (SELECT '1.01' AS code, 'Taxa condominial' AS name, 'receita' AS nature, 'analitica' AS kind, '1' AS parent
      UNION ALL SELECT '1.02', 'Fundo de reserva', 'receita', 'analitica', '1'
      UNION ALL SELECT '1.03', 'Multas e juros', 'receita', 'analitica', '1'
      UNION ALL SELECT '1.04', 'Rateios e cotas extras', 'receita', 'analitica', '1'
      UNION ALL SELECT '1.05', 'Rendimentos de aplicação', 'receita', 'analitica', '1'
      UNION ALL SELECT '1.06', 'Locação de áreas comuns', 'receita', 'analitica', '1'
      UNION ALL SELECT '1.99', 'Outras receitas', 'receita', 'analitica', '1'
      UNION ALL SELECT '2.01', 'Pessoal e encargos', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.02', 'Água e esgoto', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.03', 'Energia elétrica', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.04', 'Manutenção e conservação', 'despesa', 'sintetica', '2'
      UNION ALL SELECT '2.05', 'Limpeza e material de consumo', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.06', 'Seguros', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.07', 'Administração e honorários', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.08', 'Tarifas bancárias', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.09', 'Impostos e taxas', 'despesa', 'analitica', '2'
      UNION ALL SELECT '2.99', 'Outras despesas', 'despesa', 'analitica', '2') s
WHERE NOT EXISTS (SELECT 1 FROM `erp_chart_accounts` x WHERE x.`condominium_id` IS NULL AND x.`code` = s.code AND x.`deleted_at` IS NULL)
  AND EXISTS (SELECT 1 FROM `erp_chart_accounts` p WHERE p.`condominium_id` IS NULL AND p.`code` = s.parent AND p.`nature` = s.nature
    AND p.`kind` = 'sintetica' AND p.`deleted_at` IS NULL);

INSERT INTO `erp_chart_accounts` (`condominium_id`, `code`, `name`, `nature`, `kind`, `notes`)
SELECT NULL, s.code, s.name, 'despesa', 'analitica', 'Plano padrão Connect (ADR 0152).'
FROM (SELECT '2.04.01' AS code, 'Manutenção elétrica' AS name
      UNION ALL SELECT '2.04.02', 'Manutenção hidráulica'
      UNION ALL SELECT '2.04.03', 'Elevadores'
      UNION ALL SELECT '2.04.04', 'Jardinagem'
      UNION ALL SELECT '2.04.05', 'Pintura e obras'
      UNION ALL SELECT '2.04.99', 'Outras manutenções') s
WHERE NOT EXISTS (SELECT 1 FROM `erp_chart_accounts` x WHERE x.`condominium_id` IS NULL AND x.`code` = s.code AND x.`deleted_at` IS NULL)
  AND EXISTS (SELECT 1 FROM `erp_chart_accounts` p WHERE p.`condominium_id` IS NULL AND p.`code` = '2.04' AND p.`nature` = 'despesa'
    AND p.`kind` = 'sintetica' AND p.`deleted_at` IS NULL);
