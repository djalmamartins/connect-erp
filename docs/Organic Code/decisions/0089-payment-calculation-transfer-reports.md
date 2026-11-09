# 0089 — Relatórios de pagamentos, memória de cálculo e transferências

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Páginas: AP-074, AP-075, AP-076

## Contexto

Eram as três últimas páginas de relatório sem backend. Todas leem dados que já existem:
- lançamentos pagos (ADR 0022);
- regra de cobrança e índices (ADR 0040);
- transferências entre contas (ADR 0057).

## Decisão

- **Serviço `PaymentReports`:** somente leitura, sem migration. Escopo por condomínio. Período de até 366
  dias e teto de 20.000 linhas, como nos relatórios de recebimentos (ADR 0067).
- **Registro de pagamentos (AP-074):**
  - lançamentos com `paid_on` no período; por padrão as despesas (`nature=receita` ou `all` para as outras);
  - mostra fornecedor com o documento mascarado, conta do plano, conta bancária, unidade e se foi pago
    depois do vencimento;
  - total geral e total por conta bancária.
  - Exige `erp.entries.view`.
- **Memória de cálculo (AP-075):**
  - para cada receita vencida e em aberto na data `as_of`, mostra o principal, os meses de índice com a
    taxa e o fator acumulado, a correção, a multa, os juros e o total;
  - usa o mesmo `LateCharges::compute` da cobrança, então o número é o mesmo do aviso e do acordo;
  - mês sem taxa aparece em `missing_index` e não muda o fator;
  - exige um condomínio (a regra é dele) e pode filtrar uma unidade.
  - Exige `erp.entries.view` e `erp.agreements.view`.
- **Transferências (AP-076):**
  - transferências do período com origem, destino e situação;
  - as canceladas aparecem com o motivo, mas ficam fora do total (`active_only=1` as esconde).
  - Exige `erp.entries.view` e `erp.bank_accounts.view`.
- **CSV:** o mesmo CSV seguro do ADR 0081, com o limite de 30 exportações por hora do ADR 0087. Cada
  exportação fica na auditoria.

## Consequências

- As rotas `/api/v1/reports/payments`, `/reports/calculation` e `/reports/transfers` e o OpenAPI entram
  no alinhamento dos arquivos compartilhados (docs/handoff-0089-shared.md).
- AP-171 (Integrações) é a única página ainda sem backend. Ela depende da decisão sobre o adaptador
  bancário real.
