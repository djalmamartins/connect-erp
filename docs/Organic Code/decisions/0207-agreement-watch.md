# 0207 — Acompanhamento dos acordos

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0039 (acordos), 0056 (regra de cobrança e quebra), CollectionReports (relatório de acordos por
  período)

## Contexto

A administradora faz acordo com a unidade inadimplente e depois precisa acompanhar se as parcelas estão sendo pagas.
Hoje ela tem:

- o relatório de acordos por período, que mostra quanto está vencido;
- a situação de quebra de um acordo por vez (`breachStatus`).

Falta a pergunta do dia a dia: "quais acordos já podem ser quebrados pela regra do condomínio e quais estão atrasados e
chegando lá?". Sem ela, a ligação para a unidade só acontece quando o acordo já quebrou.

O síndico pede a mesma visão da carteira de acordos.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/agreement-watch?condominium_id=&as_of=`. O service é
`AgreementWatch`.

### Classificação

Cada acordo ativo do escopo é classificado pela regra pura `classify()`, com o mesmo cálculo da quebra do acordo
(`LateCharges::breach`) e a regra de cada condomínio (`BillingRules::rule`, ADR 0056):

- **`breach_due`:** atende à regra de quebra (dias de atraso acima do limite, ou parcelas vencidas no limite), com os
  motivos.
- **`late`:** tem parcela vencida em aberto, ainda abaixo da regra. Traz:
  - `days_to_breach`: dias até passar do limite de atraso;
  - `installments_to_breach`: parcelas vencidas que faltam para o limite, quando a regra usa parcelas.
- **`on_track`:** nada vencido. Vence hoje ainda não conta como vencida.

### Resultado

- **Totais por situação:** quantidade, em aberto e vencido.
- **Itens** de `breach_due` e `late`, mais graves primeiro, até 500 (com `truncated`).
- **Fora do relatório:** acordos quebrados e cancelados (já resolvidos) e parcelas canceladas.

### Nada muda

Marcar a quebra continua sendo a ação explícita do acordo (`Agreements::breach`, com motivo e o aviso à unidade). Este
relatório não grava, não audita e não envia nada.

### Acesso

- **Escopo (ADR-002):** um condomínio (fora do escopo dá 404) ou todos os do escopo.
- **Limite:** até 2000 acordos ativos por consulta; acima disso, 422 pedindo um condomínio.
- **Permissão:** exige `erp.access`, `erp.agreements.view` e `erp.entries.view`; sem uma delas, 403 (fail-closed).
- **Entrada inválida** (data, condomínio) dá 422.
- **Consultas:** parametrizadas.

### Dados expostos

Saem condomínio, rótulo da unidade, número do acordo, valores, datas e a regra aplicada. Nenhum nome, documento,
e-mail ou telefone de morador.

## Consequências

- **Testes.**
  - `Unit\AgreementWatchTest` (SQLite):
    - regra pura: em dia, vence hoje, atrasado com dias e parcelas até a quebra, quebra por dias, quebra por parcelas,
      regra sem limite de parcelas, parcela cancelada;
    - quebrados e cancelados fora;
    - validação, escopo (404) e o contrato (somente leitura, sem dado de morador);
    - controller e rota.
  - `Integration\AgreementWatchTest` (MariaDB, banco `*_test`):
    - acordos criados de verdade, com a regra padrão (`breach_due` e `late`, mais grave primeiro);
    - a regra do condomínio trocada (90 dias, sem limite de parcelas);
    - antes do primeiro vencimento, tudo em dia;
    - nada quebrado, nenhum aviso na outbox e nenhuma auditoria nova;
    - escopo.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Sem tela nova neste patch.** Quando a tela existir (painel de acordos ou relatório do síndico), ela ganha o artigo
  da Central de Ajuda.
