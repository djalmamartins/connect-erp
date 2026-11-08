# CN-003 — Contas bancárias

Origem: núcleo financeiro do Connect ERP (cadastro base). O inventário APControle trata contas bancárias dentro de
pagamentos e boletos (AP-031, AP-035); aqui é página própria da Connect.
Rota: `/erp/financeiro/contas-bancarias` (grupo Financeiro). Implementação: motor de cadastros (ADR 0014).
Integrações bancárias: `docs/banking/BANKING_INTEGRATIONS.md`.

## Regras propostas (INFERIDO, a confirmar com o proprietário)

| Regra | Situação |
|---|---|
| Toda conta pertence a um condomínio (escopo obrigatório, ADR-002) | INFERIDO |
| Banco (código COMPE), agência, conta e dígito, tipo (corrente/poupança/aplicação), carteira de cobrança opcional | INFERIDO |
| Saldo inicial e data de abertura; uma conta principal por condomínio | INFERIDO |
| Nenhuma credencial de API bancária fica neste cadastro (segredos fora do banco de cadastros) | INFERIDO |
