# 0385 — Prazo do PHP para os PDFs grandes

- Status: Aceito
- Data: 18/11/2026
- Contexto: continua os ADRs 0383 e 0384 (memória dos PDFs na hospedagem).

## Contexto
Medições de 18/11/2026:

| PDF | Tamanho | Memória | Tempo |
| --- | --- | --- | --- |
| Lista (ADR 0383) | 5.000 linhas | 74 MB | ~23 s |
| Livro-caixa (ADR 0384) | 1.000 lançamentos | 547 MB | ~23 s |
| Prestação de contas | 300 receitas + 75 despesas | 78 MB | 3,1 s |
| Prestação de contas | 800 receitas + 200 despesas | 240 MB | 13,5 s |

Na hospedagem compartilhada o `max_execution_time` costuma ser 30 s. O `deploy-check` roda pelo CLI, onde esse prazo é
sempre 0, então não dá para conferir lá. Só o pacote do livro-caixa, a importação e o PDF público da prestação de
contas pediam mais prazo (`set_time_limit` no controller).

## Decisão
- `Source\Support\PdfTime::extend()` pede 120 s antes de montar o PDF, só aumentando: prazo 0 (sem limite) e prazo maior
  já dado por quem chamou ficam como estão.
- Chamado no começo do `pdf()` da lista (`ListDocument`), do livro-caixa, da prestação de contas e do livro da prestação.

## Consequências
- Um PDF grande não para no meio por prazo, com 120 s de folga sobre os ~23 s medidos.
- Se a hospedagem desligar `set_time_limit` (`disable_functions`), nada muda e o limite do hPanel vale; conferir no piloto.
- Testes: `PdfTimeTest` (regra só aumenta; os quatro PDFs pedem o prazo; o CLI continua sem limite).
