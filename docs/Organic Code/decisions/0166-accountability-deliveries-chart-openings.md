# 0166 — Prestação de contas mensal, registros de entregas e saldo inicial do plano de contas

Data: 2026-11-10 · Status: proposto para revisão · Continuação do inventário do ADR 0164 (depois do 0165)

## Contexto

Três páginas de exemplo ainda não tinham backend: AP-101 Prestação de contas, AP-136 Registros de Entregas e
AP-161 Saldo inicial do plano de contas (`docs/process/PAGINAS-EXEMPLO.md`).

## Decisão

- **AP-101 Prestação de contas** (`Services/Erp/Accountability`, `GET /api/v1/reports/accountability`):
  - O pacote mensal de um condomínio é montado só com os services que já existem, sem SQL próprio:
    - o balancete da competência (AP-079, `FinancialReports::trialBalance`);
    - a DRE do mês por competência (AP-082, `AccountingReports::incomeStatement`);
    - os saldos bancários do mês (AP-073, `AccountingReports::accountBalances`);
    - a inadimplência no último dia do mês (`Agreements::delinquency`, a mesma do AP-057);
    - o status do fechamento (AP-030, `MonthClosing::status`).
  - `condominium_id` é obrigatório (sem ele, 422) e precisa estar no escopo (fora, 404). `month` vem em
    AAAA-MM (inválido, 422).
  - `assemble()` é a regra pura. Monta o resumo, lista até 500 unidades inadimplentes (a maior dívida
    primeiro, com `truncated`) e gera três avisos: mês não fechado, receita ou despesa da competência em
    aberto, e contas vencidas no fim do mês.
  - Sem CSV: o pacote é para tela e impressão. Cada parte já tem o próprio CSV no relatório de origem.
- **AP-136 Registros de Entregas** (`Services/Erp/DeliveryLog`):
  - A tabela `erp_deliveries` e as permissões `erp.deliveries.view` e `erp.deliveries.manage` já existem
    (migration 20261108_0525, ADR 0042). A chegada continua no cadastro de Entregas (AP-135, motor de
    cadastros). Não há migration nova.
  - `GET /api/v1/reports/deliveries`:
    - filtros `status`, `from`/`to` (até 366 dias) e `unit_id`;
    - resumo com aguardando, retiradas, canceladas, média de dias até a retirada e a espera mais antiga;
    - LIMIT de 5000 com `truncated`;
    - quem recebeu é o `created_by` do registro.
  - `POST /api/v1/delivery-pickups` dá baixa na retirada:
    - só para entrega aguardando; já retirada ou cancelada responde 409;
    - grava o nome de quem retirou (2 a 160 caracteres, sem caracteres de controle) e a data (padrão hoje,
      entre a chegada e hoje);
    - entrega fora do escopo ou inexistente responde 404, sem dizer qual dos dois;
    - o UPDATE é condicional (`status = 'active'`), o que evita a baixa dupla em concorrência;
    - a auditoria registra a mudança de situação e a data, mas não o nome. Foto e documento de quem retira
      não são guardados.
  - CSRF fica no middleware `ApiAuthenticated`, como nas outras rotas de escrita.
- **AP-161 Saldo inicial do plano de contas** (`Services/Erp/ChartOpenings`, `GET/POST /api/v1/chart-account-openings`):
  - Segue o mesmo padrão do AP-162: tabela `erp_chart_account_openings`, com um valor com sinal por
    condomínio e conta, numa data até hoje (migration 20261109_0703, com down), além de `signedCents`, das
    permissões `erp.settings.view` e `erp.settings.manage` e da auditoria.
  - Só a conta analítica, ativa, do plano padrão ou do próprio condomínio recebe saldo. A sintética responde
    422; a leitura (`tree()`) calcula o saldo da sintética como a soma das analíticas.
  - A gravação faz SELECT e depois UPDATE ou INSERT, em vez de `ON DUPLICATE KEY`. O efeito é o mesmo, e o
    valor anterior vai para a auditoria.
  - O saldo inicial ainda não entra no saldo anterior do Livro Razão (ADR 0165). Isso fica para quando a
    regra de abertura for decidida (a data do saldo inicial em relação ao período).

## Consequências

- `AccountabilityDeliveriesOpeningsTest` (5 testes) cobre:
  - o pacote mensal, os avisos e o truncamento;
  - escopo e 422 da prestação;
  - os filtros, o resumo e o escopo do histórico de entregas;
  - a baixa (409, 404, 422 e o nome saneado);
  - o saldo inicial (só analítica, a soma nas sintéticas, a atualização sem duplicar, o escopo e a 422);
  - a permissão nos controllers.
- Uma migration nova (20261109_0703) e nenhuma página nova no catálogo.
