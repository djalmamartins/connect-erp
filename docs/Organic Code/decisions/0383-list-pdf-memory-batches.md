# 0383 — PDF das listas em lotes (memória e tempo da hospedagem)

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.4 ("o `memory_limit` do PHP por processo pode apertar nos PDFs grandes, medir no piloto").

## Contexto
Medição antes do piloto, com uma lista de 7 colunas no leiaute do modelo 3 (`ListDocument`, ADR 0314), PHP 8.3:

| Linhas | Antes | Depois |
| --- | --- | --- |
| 500 | 128 MB, 4,3 s | 67 MB, 2 s |
| 2.000 | 1.241 MB, 98 s | 68 MB, 9,5 s |
| 5.000 (limite do PDF) | estourou 2 GB | 74 MB, 23 s |

O Dompdf monta a lista inteira como uma tabela só, e o custo dela cresce bem mais que o número de linhas. Na Cloud
Startup (limite de memória por processo de algumas centenas de MB), uma lista de inadimplentes ou moradores acima de
~600 linhas daria erro 500.

## Decisão
- Uma tabela por folha: blocos de `PDF_ROWS_PER_SHEET` (17) linhas, cada um com o mesmo cabeçalho do modelo 3 e
  começando em folha nova. É o número que cabe numa folha A4 deitada com texto de uma linha; a folha fica igual à de antes.
- Lotes de `PDF_SHEETS_PER_BATCH` (20) folhas: cada lote é um Dompdf separado, liberado antes do próximo, e os lotes
  são juntados com o FPDI (já usado na prestação de contas). A numeração da página continua de um lote para o outro
  (`footer(..., $pageOffset)`). Lista de até 340 linhas segue pelo caminho de antes, num Dompdf só.
- O aviso "N linha(s) a mais não couberam no PDF" sai só no último lote.

## Consequências
- A memória fica no tamanho de um lote (~70 MB de pico), qualquer que seja a lista.
- Linha com texto que quebra em duas faz a folha transbordar: as linhas que sobram vão para uma folha com o cabeçalho
  repetido, e o bloco seguinte começa em folha nova (pode sobrar uma folha curta). Aceito: lista longa é para conferir.
- 5.000 linhas ainda levam ~23 s; se o `max_execution_time` da hospedagem for 30 s, conferir no piloto. O Excel continua
  sendo o formato para listas inteiras.
- Os outros PDFs (livro-caixa, prestação de contas, vistoria) não mudam aqui; medir no piloto.
- Testes: `ListDocumentTest` (uma tabela por folha; lista de 1.365 linhas com todas as folhas e menos de 64 MB). Com os
  lotes desligados o teste falha (418 MB).
