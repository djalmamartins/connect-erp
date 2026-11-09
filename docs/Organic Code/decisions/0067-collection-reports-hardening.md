# 0067 — Relatórios de cobrança: CSV mais estrito e teto de recebimentos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (sexta rodada)

## Contexto

A revisão dos relatórios de cobrança (ADR 0081) conferiu quatro pontos: o escopo por condomínio, a
máscara do documento, a injeção de fórmula no CSV e o custo das consultas grandes. O escopo e a
máscara estão certos. Ficaram dois pontos.

## Decisão

- **CSV mais estrito.** `CollectionReports::csvCell` já prefixava com apóstrofo a célula que começa
  com `=`, `+`, `-`, `@`, tab ou CR. Passa a prefixar também nestes casos:
  - quando o sinal vem depois de espaços, NBSP, BOM ou caracteres de controle, que algumas planilhas
    descartam ao importar;
  - quando a célula começa com LF;
  - quando o sinal está em largura total (`＝`, `＋`, `－`, `＠`).

  Texto com o sinal no meio (`a=b`, `101 - bloco A`, e-mail) não muda.
- **Teto de recebimentos.** O relatório de recebimentos lia todas as receitas pagas do período, até
  366 dias e em todos os condomínios do escopo, e montava tudo em memória, inclusive para o CSV.
  Agora a consulta lê no máximo `MAX_RECEIPT_ROWS` (20.000) linhas. Acima disso, responde 422 e
  pede para reduzir o período ou escolher um condomínio. A consulta usa o índice
  `idx_erp_entries_paid` (`condominium_id`, `paid_on`) do ADR 0064.

## Fora deste ADR

- Paginação dos relatórios. O teto evita o pior caso; paginar fica para quando houver volume real.
- Valor negativo no CSV: os encargos líquidos negativos (`-12,50`) saem com apóstrofo e viram texto
  na planilha. É o preço da regra "sem exceção" do ADR 0081.

## Consequências

- `CollectionReportsTest::testCsvCellCoversLeadingSpaceControlAndFullWidth` e
  `CollectionReportsScopeIntegrationTest` cobrem as regras.
