# CN-007 — Livro Caixa

Origem: pedido do proprietário em 11/11/2026 (livro caixa mensal pronto, com notas e comprovantes). Não existe no
inventário APControle; é página própria da Connect.
Rota: `/erp/prestacao-de-contas/livro-caixa` (grupo Prestação de contas). Decisão: ADR 0174.
API: `GET /api/v1/reports/cash-book?condominium_id=&competence=AAAA-MM`; anexos em `/api/v1/entries/{id}/attachments`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Um condomínio (o da barra superior); sem condomínio, pede para escolher | CONFIRMADO |
| Mês AAAA-MM até o atual; sem mês, abre o mês anterior | CONFIRMADO |
| Regime de caixa: lançamentos válidos com "Pago em" no mês, em ordem de data (receitas antes no mesmo dia) | CONFIRMADO |
| Saldo anterior = soma das contas bancárias no dia 1 (saldo inicial + movimento + transferências) | CONFIRMADO (mesmo cálculo da prestação de contas, ADR 0037) |
| Saldo corrido por linha; saldo final conferido com a soma das contas bancárias, diferença exposta | CONFIRMADO |
| Transferências entre contas do condomínio fora das linhas, no quadro das contas bancárias | CONFIRMADO |
| Anexos numerados A1, A2... na ordem do livro (nota fiscal, comprovante, recibo, outro) | CONFIRMADO |
| Despesas pagas sem nota fiscal, comprovante nem recibo listadas como pendência | CONFIRMADO |
| Competência aberta = Prévia; fechada = Mês fechado | CONFIRMADO (ADR 0031) |
| PDF com marca da Connect, dados do condomínio, assinaturas e anexos de imagem; pacote ZIP com todos os anexos | CONFIRMADO |
| Ver e baixar exigem `erp.entries.view`; downloads auditados e limitados | CONFIRMADO |
| Anexar e remover nos lançamentos exigem `erp.entries.manage`; remover trava em mês fechado | CONFIRMADO |

## PENDENTE

- PDFs anexados dentro do PDF do livro (hoje vão no pacote).
- Termo de abertura/encerramento por exercício.

## Estados

- Sem banco ou sem permissão: demonstração.
- "Todos os condomínios": pede para escolher um.
- Mês sem movimento: estado vazio, com saldo anterior e final iguais.
- Mais de 3.000 movimentos no mês: erro pedindo para falar com o suporte.
