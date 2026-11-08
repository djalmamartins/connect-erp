# CN-002 — Centros de custo

Origem: núcleo financeiro do Connect ERP (cadastro base). Não existe como cadastro próprio no inventário APControle
(lá só há o relatório AP-049 e o saldo inicial AP-162); é página própria da Connect.
Rota: `/erp/financeiro/centros-de-custo` (grupo Financeiro). Implementação: motor de cadastros (ADR 0014).

## Regras propostas (INFERIDO, a confirmar com o proprietário)

| Regra | Situação |
|---|---|
| Sem condomínio = centro de custo padrão da Connect, válido para todos; com condomínio = próprio dele (ADR-002) | INFERIDO (mesmo modelo do plano de contas, ADR 0019) |
| Código único por plano (padrão ou condomínio), nome obrigatório, situação ativo/inativo, exclusão lógica | INFERIDO |
| Lançamentos e relatórios (AP-049, AP-162) referenciam o centro de custo | INFERIDO |
