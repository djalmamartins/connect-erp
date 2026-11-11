# 0184 — Extrato da unidade

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0071 (painel da unidade, AP-003), 0080/0081 (cobrança e inadimplência), 0057 (recibos e
  declarações, AP-125)

## Contexto

O painel da unidade (AP-003) mostra só o que está vencido hoje e o que vence a seguir. Não havia o histórico de um
período. Esse histórico é o que o atendimento usa quando o condômino pergunta o que pagou e o que deve, e o que se
confere antes de emitir uma declaração de quitação.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/unit-statement?unit_id=&from=&to=`, com `format=csv`. O service é
`UnitStatement`. O período é de até 366 dias, como os relatórios contábeis.

### As linhas

As receitas da unidade com vencimento no período, cada uma com a sua situação:

- `paid`: paga até a data de corte;
- `open`: a vencer;
- `overdue`: vencida e em aberto na data;
- `renegotiated`: a conta entrou num acordo. As parcelas do acordo aparecem como linhas próprias.

Lançamento cancelado fora de acordo, excluído ou de despesa não entra.

A **data de corte** é o fim do período, ou hoje quando o período passa de hoje.

### Os totais

| Total | O que soma |
|---|---|
| Saldo anterior | o que vencia antes do período e não estava pago no primeiro dia dele |
| Cobrado no período | as linhas do período, sem as renegociadas, para não contar duas vezes com as parcelas |
| Pago no período | pela data do pagamento, de qualquer vencimento |
| Em aberto no fim | o que vencia até o fim e não estava pago nele |
| Vencido na data | o que vencia antes da data de corte e não estava pago nela |

Vale a conta: saldo anterior + cobrado − pago = em aberto no fim.

### CSV

O CSV segue o padrão dos outros relatórios:

- **Colunas:** vencimento, competência, descrição, pago em, situação, acordo e valor.
- **Linhas:** primeiro o saldo anterior, no fim os totais.
- **Limite e auditoria:** até 30 exportações por hora, com auditoria `export`.
- **Formato:** fórmulas neutralizadas e BOM UTF-8.

### Dados pessoais e escopo

- **Dados pessoais:** sem nome de morador; a unidade vai pelo rótulo.
- **Permissão:** exige `erp.entries.view`.
- **Escopo:** vem do condomínio da unidade. Unidade de outro condomínio, excluída ou inexistente dá 404 igual, sem
  dizer se existe.
- **Entradas inválidas:** sem unidade ou com período inválido dá 422.
- **Consultas:** parametrizadas, sem o mesmo parâmetro repetido (por causa do prepare nativo do PDO no MySQL).
- **Limite de linhas:** 2000 linhas, com `truncated`.

## Consequências

- **Testes.** `UnitStatementTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) monta uma unidade com:
  - conta vencida antes do período;
  - conta paga dentro dele;
  - conta paga;
  - conta renegociada;
  - parcelas de acordo (uma paga, uma vencida);
  - conta cancelada, conta excluída, despesa, outra unidade e outro condomínio.

  O teste confere:
  - as situações e quais linhas entram;
  - os totais (800,00 + 1.090,00 − 1.170,00 = 720,00 em aberto);
  - o corte em hoje;
  - o CSV com a fórmula neutralizada;
  - o 404 igual para unidade de fora, excluída e inexistente;
  - as entradas inválidas;
  - a regra pura;
  - o controller.
- **Rota e documentação no patch:** a rota entra no `index.php` e na OpenAPI, dentro do patch.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. O painel da unidade (AP-003) pode ganhar um link
  "Ver extrato" quando a frente de layout quiser.
