# 0187 — Proposta de previsão orçamentária a partir do realizado

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** AP-163 (previsão orçamentária), AP-084 (previsto x realizado), ADR 0031 (competência fechada),
  0180 (orçamento na prestação de contas), 0186 (previsão de caixa)

## Contexto

O síndico precisa levar à assembleia a previsão orçamentária do ano seguinte. Hoje ela é digitada conta a conta, mês a
mês, no `POST /budgets`. Quase sempre o ponto de partida é o mesmo: o que foi gasto e arrecadado nos últimos 12 meses,
com um reajuste. O sistema já tem esses dados, então pode montar a sugestão.

## Decisão

Service `BudgetProposal` e duas rotas novas:

- `GET /api/v1/budgets/proposal?condominium_id=&year=&adjustment=&distribution=` — somente leitura.
- `POST /api/v1/budgets/proposal` `{condominium_id, year, adjustment?, distribution?, chart_account_ids?}` — grava.

### Sugestão

- **Período-base:** os 12 meses completos antes de hoje, por competência (em novembro de 2026: 2025-11 a 2026-10).
  Só lançamentos válidos (ativos e não excluídos).
- **Contas:** analíticas ativas, do padrão ou do condomínio. Conta inativa fica fora, porque o `setBudget` não a aceita.
- **Reajuste:** de −50% a +100%, aceita vírgula decimal. Padrão 0.
- **Distribuição:**
  - `flat` (padrão): o total reajustado dividido em 12; a sobra dos centavos vai para dezembro;
  - `seasonal`: cada mês segue o mesmo mês do período-base, reajustado.
  - Nunca sai valor negativo (um estorno no período não vira previsto negativo).
- **Comparação:** cada conta traz o previsto que já existe no ano; os totais vêm por natureza e com o resultado.
- **Aviso `deficit`:** quando a despesa sugerida passa da receita, com o reajuste de receita que zeraria o déficit.

### Gravação

- Vai pelo `ErpSettings::setBudget`, conta a conta: mesma validação, mesma trava de competência fechada, mesma auditoria.
- Pode gravar todas as contas da sugestão ou só as escolhidas em `chart_account_ids`.
- Antes de gravar a primeira conta, confere tudo: ano sem mês fechado (409) e contas escolhidas todas na sugestão (422).
  Assim a gravação não fica pela metade.

### Permissões e escopo

- **Ver:** `erp.access`, `erp.settings.view` e `erp.entries.view` (a sugestão mostra o realizado).
- **Gravar:** também `erp.settings.manage`; CSRF pelo `ApiAuthenticated`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** ano fora de 2000 a hoje + 5, reajuste fora da faixa, distribuição desconhecida ou sem
  condomínio dá 422.
- **Consultas:** parametrizadas. Nenhum dado pessoal: só contas e valores.

## Consequências

- **Testes.** `BudgetProposalTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2; o PDO de teste só
  traduz o upsert do MySQL) confere:
  - o período-base, os filtros (cancelada, excluída, fora do período, conta inativa, conta e lançamento de outro
    condomínio), flat com a sobra em dezembro, sazonal, os totais e o aviso de déficit;
  - a gravação de todas e de só uma conta, com outro condomínio intacto;
  - a recusa antes de gravar (conta fora da sugestão, lista vazia ou inválida, ano com mês fechado; mês reaberto e
    outro condomínio não travam);
  - as regras puras, o escopo, as entradas inválidas, o controller e as rotas.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. A tela de previsão orçamentária pode ganhar o botão
  "Sugerir a partir do realizado" quando a frente de layout quiser.
