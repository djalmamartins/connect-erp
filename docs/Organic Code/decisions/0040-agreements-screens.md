# 0040 — Telas de inadimplência e acordos (AP-033 e AP-034)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O backend de inadimplência e acordos (ADR 0039) expõe `GET /api/v1/delinquency` e
`/api/v1/agreements` (lista, detalhe, criar e cancelar). As páginas AP-033 (Acordos) e AP-034
(Parcelas de Acordo) mostravam só a demonstração.

## Decisão

- `Panel::agreementData` carrega os dados no contexto da barra superior, com
  `erp.agreements.view`; `canManageAgreements` vem de `erp.agreements.manage`. Sem permissão ou sem
  banco, a página segue com a demonstração (`Panel::ownTemplate`).
- **AP-033 Acordos** (`pages/finance/agreements.php`):
  - indicadores por faixa de atraso;
  - inadimplência por unidade, com "Fazer acordo" só em unidade identificada;
  - lista de acordos (ativo, quitado, cancelado), detalhe com parcelas e contas renegociadas, e
    cancelamento com motivo só sem parcela paga (o serviço confere de novo, com boleto e fechamento).
- **Fazer acordo** (modal): escolhe as contas vencidas da unidade, a conta de receita analítica do
  plano (padrão ou do condomínio, buscada em todas as páginas da API de cadastros), as parcelas, o
  primeiro vencimento, os encargos e o desconto. O resumo mostra a conta antes de enviar.
  Encargos são informados à mão: a regra de multa e juros ainda depende do Djalma.
- **AP-034 Parcelas** (`pages/finance/agreement-parcels.php`): parcelas dos acordos ativos da página,
  das vencidas para as pagas (`Panel::flattenParcels`, regra pura).
- **AP-038 Retornos** passa a listar também os arquivos importados (`BankReturns::files`), acima da
  fila de divergências.

## Consequências e lacunas

- AP-034 usa o detalhe de cada acordo da página (até 25 consultas). Uma listagem própria de parcelas
  na API evitaria isso, se o volume crescer.
- Quebra de acordo por atraso e cálculo de multa e juros continuam pendentes (ADR 0039).
