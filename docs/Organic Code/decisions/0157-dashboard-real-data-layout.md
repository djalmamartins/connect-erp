# ADR 0157 — Dashboard da administradora todo com dados reais e novo layout

- Situação: aceita (10/11/2026)
- Contexto: pedido do Djalma em 10/11/2026 (áudio): "a dashboard ainda é uma página demonstrativa, uma parte
  dela; vamos colocar ela toda para funcionar", e as caixinhas de cadastro no topo estavam feias. Antes:
  cards e alertas reais (ADR 0053/0070) e o resto (gráfico, inadimplência por atraso, quadro de
  condomínios, atividades, agenda, período, exportar, comunicado rápido) vindo de `database/fixtures/erp-demo/dashboard.php`.

## Decisão

- **Serviço `Source\Services\Erp\FinancialDashboard`** (somente leitura, escopo por condomínio, ADR-002),
  no condomínio da barra superior ou em todos do escopo:
  - `finance()`: 6 competências até a escolhida (lançado e realizado de receitas e despesas, por
    competência, como o balancete AP-079); o mês com recebido, pago, resultado (recebido − pago) e previsto
    (lançado); inadimplência por atraso com as faixas do relatório de inadimplentes (1-30, 31-60, 61-90,
    90+, receitas vencidas e não pagas até hoje); quadro por condomínio com unidades, % arrecadado e % de
    inadimplência da competência. Situação: Em dia até 5%, Atenção até 15%, Crítico acima, "Sem
    lançamentos" sem receita no mês. Pede `erp.entries.view`.
  - `agenda()`: próximos 30 dias de assembleias convocadas/abertas (`erp.assemblies.view`), manutenções
    agendadas (`erp.maintenances.view`) e reservas aguardando/confirmadas (`erp.reservations.view`).
  - `activities()`: últimas ações de `system_audit_logs` numa lista fixa de tabelas do ERP, filtradas pelo
    condomínio do registro. Mostra módulo, ação, número do registro, condomínio e quem fez; nada do
    conteúdo do registro. Pede `erp.audit.view`.
- **API REST junto (V3):** `GET /api/v1/dashboard/finance?condominium_id=&competence=`,
  `/dashboard/agenda` e `/dashboard/activities`, no OpenAPI. Fora do escopo = 404; competência inválida = 422.
- **Sem demonstração no dashboard.** A tela não lê mais o fixture; cada bloco é `null` sem permissão, sem
  banco ou em falha (registrada no log) e simplesmente não aparece. Sem nenhum bloco, o estado vazio.
  O seletor de período vira `?competencia=AAAA-MM` (últimos 12 meses); "Exportar" vira **Balancete do
  mês** (abre o AP-079 da competência) e o modal de comunicado rápido, que não enviava nada, vira link para
  **Comunicados gerais** (AP-111), que já envia de verdade.
- **Layout, de cima para baixo:** indicadores de cobrança e operação (a receber vencido, a pagar em 7 dias,
  manutenções, ocorrências); **Receitas e despesas** (mês do balancete + gráfico de 6 meses) ao lado de
  **Inadimplência por atraso**; depois, à esquerda, o quadro de **Condomínios** (só em "Todos") e
  **Atividades recentes** + **Agenda**; na lateral, **Alertas** e **Cadastros** (os contadores que eram as
  caixinhas do topo, agora lista compacta).

## Consequências

- O fixture `erp-demo/dashboard.php` continua só para as notificações de exemplo do sino sem banco.
- Testes: `FinancialDashboardTest` (regras puras), `FinancialDashboardIntegrationTest` (somas, faixas,
  quadro, escopo, agenda e atividades no MariaDB) e o smoke de renderização com os novos blocos.
- Lacuna: o gráfico usa competência (como o balancete), não data de pagamento; o demonstrativo por caixa
  segue no AP-051.
