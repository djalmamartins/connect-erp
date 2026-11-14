# CN-008 — Honorários

Origem: pedido do proprietário em 14/11/2026 ("quando eu cadastro um condomínio, o valor que ele vai me pagar, para ter um
controle como se fosse uma assinatura, e aí eu poderia solicitar a nota"). Não existe no inventário APControle; é página
própria da Connect. Não é cobrança de SaaS (ADR-002): é o contrato da Connect com os condomínios que ela administra.
Rota: `/erp/condominios/honorarios` (grupo Condomínios). Decisão: ADR 0402.
API: `GET /api/v1/management-fees`, `POST /api/v1/condominiums/{id}/management-fee`, `GET|POST /api/v1/condominiums/{id}/nfse-requests`,
`POST /api/v1/nfse-requests/{id}/cancel`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Lista os condomínios do escopo com valor mensal, situação do contrato e nota da competência | CONFIRMADO |
| Total mensal e quantidade dos contratos ativos | CONFIRMADO |
| Um contrato por condomínio: valor, início, fim opcional, dia de vencimento (1 a 28), descrição do serviço, situação, observações | CONFIRMADO |
| Chave `nfse.enabled` (Configurações › Financeiro), desligada por padrão; desligada, nenhum pedido é aceito e o botão some | CONFIRMADO (pedido do proprietário, 14/11/2026) |
| Pedido de nota só com contrato ativo e competência dentro do contrato; um pedido aberto por competência | CONFIRMADO |
| Cancelar pedido não emitido, com motivo; libera a competência | CONFIRMADO |
| Consultar exige `erp.management_fees.view`; gravar, pedir e cancelar exigem `erp.management_fees.manage` | CONFIRMADO |
| Auditoria em toda gravação, sem dados pessoais | CONFIRMADO |

## PENDENTE

- Envio da DPS à API Nacional da NFS-e com o certificado A1 da Connect (produção restrita antes de produção), número, chave, XML e DANFSe.
- Código de serviço, alíquota do ISS, município e regime tributário da Connect (o proprietário vai confirmar com o contador).
- Nota do condomínio para o condômino, emitida na baixa do boleto (depende da norma de dezembro/2026).

## Estados

- Sem banco: "Honorários indisponíveis".
- Sem condomínios no escopo: estado vazio.
- Condomínio fora do escopo em `?condominio=`: aviso de erro, lista continua.
