# 0181 — Conferência antes de fechar o mês

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0031 (fechamento mensal, AP-030);
  - 0074 (extrato OFX, AP-140);
  - 0166 (prestação de contas);
  - 0167 (Balanço);
  - 0179 (publicação congelada).

## Contexto

Fechar o mês trava os lançamentos. Desde o ADR 0179, é também o que libera a publicação da prestação de contas. Mas
nada avisava, antes de fechar, que o pacote ia sair errado. Por exemplo:

- o Balanço não fechava, porque faltava o saldo inicial do PL;
- havia uma despesa paga sem conta bancária, e o caixa não batia;
- o extrato do mês não tinha sido conciliado.

Quem fechava só descobria depois, ao reabrir com motivo.

## Decisão

Uma rota nova, somente leitura: `GET /api/v1/closings/readiness?condominium_id=&competence=`. O service é
`ClosingReadiness`.

### O que ela devolve

A lista de conferências do mês, cada uma com código, nível, mensagem, quantidade e valor. Também devolve `ready`
(nenhum erro) e a contagem de erros e avisos.

| Nível | Código | Quando |
|---|---|---|
| erro | `balance_sheet` | o Balanço do último dia do mês não fecha (diferença diferente de zero) |
| erro | `paid_without_bank` | lançamento da competência pago sem conta bancária |
| aviso | `previous_open` | o mês anterior tem lançamentos e não está fechado |
| aviso | `open_entries` | receitas ou despesas da competência em aberto |
| aviso | `unmatched_statement` | linhas do extrato importado do mês sem conciliação |
| aviso | `negative_bank` | conta bancária com saldo negativo no último dia do mês |
| informação | `already_closed` | a competência já está fechada |

### De onde vêm os números

Dos services que já existem: `MonthClosing::status`, `AccountingReports::accountBalances` e `BalanceSheet::build`. Só
três contagens têm SQL próprio, todas parametrizadas e no condomínio:

- extrato sem conciliação;
- mês anterior;
- pago sem banco.

### O fechamento não é bloqueado

A conferência é um aviso para a tela, e quem fecha decide. Bloquear agora mudaria o comportamento do
`POST /api/v1/closings` para quem já usa. Se o Djalma quiser que o erro bloqueie, é uma linha no `close()`.

### Permissões e escopo

- Exige `erp.closing.view`, `erp.entries.view` e `erp.chart.view`, porque usa o Balanço.
- Fora do escopo dá 404.
- Sem condomínio ou com competência inválida dá 422.

## Consequências

- **Testes.** `ClosingReadinessTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) monta um mês com
  todos os problemas. Ele confere:
  - os seis códigos;
  - os valores, incluindo a diferença do Balanço: 500,00 do banco sem saldo do PL + 50,00 pagos sem banco;
  - que só entra o extrato do mês e do condomínio.

  Depois corrige tudo e confere que só sobra o aviso das receitas em aberto. Também confere `already_closed`, o escopo,
  as entradas inválidas, a regra pura sem nada a avisar e o controller.
- **Rota e documentação no patch:** a rota entra no `index.php` e na `docs/api/openapi.yaml`, dentro do patch. O
  `OpenApiRoutesTest` passa.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. A tela de fechamento (AP-030) pode mostrar a lista antes do
  botão "Fechar"; quando isso entrar, o artigo do AP-030 ganha a explicação.
