# 0063 — Importações: valores sem estouro e XLSX sem explosão de memória

- Status: aceito (09/11/2026)
- Contexto: revisão de segurança das importações (`docs/security/REVIEW_API_FINANCEIRO.md`, quarta rodada), ADR 0058 e ADR 0062

## Contexto

A revisão das importações (ADR 0058) usou a lista de requisitos deixada na terceira rodada. A maior parte já estava atendida:
- upload com `is_uploaded_file` e limite de 2 MB;
- formato pela extensão e pela assinatura do conteúdo;
- arquivo fora da pasta pública, com caminho validado por regex;
- repetição barrada pelo hash por condomínio e tipo;
- conta bancária do extrato do mesmo condomínio;
- OFX lido por expressão, sem parser XML;
- XLSX com `DOCTYPE` recusado e `LIBXML_NONET`;
- limite de linhas e de transações;
- fórmulas lidas só pelo valor gravado, nunca avaliadas.

Ficaram três pontos:

1. **Valores sem teto.**
   - `OfxParser::amount` aceitava qualquer número de dígitos. Com 20 dígitos, o inteiro do PHP estourava, o resultado virava float, e o retorno `?int` com `strict_types` dava TypeError, ou seja, erro 500.
   - `ImportRules::money` convertia por float sem teto.

   É o mesmo problema corrigido em `Invoices::cents` (ADR 0062).
2. **XLSX esparso.** Uma célula `XFD1` ou uma linha `r="1048576"`, num arquivo de poucos bytes, fazia o leitor montar 16 mil colunas por linha, ou um milhão de linhas vazias. Isso esgota a memória do PHP. `columnIndex` também estourava o inteiro com uma referência de muitas letras.
3. **Tamanho do ZIP declarado pelo arquivo.** O limite contra zip bomb usava só o tamanho que o diretório do ZIP informa, e esse número vem do próprio arquivo.

## Decisão

- **Valores.** `OfxParser::amount` e `ImportRules::money` aceitam até 10 dígitos na parte inteira, ignorando os zeros à esquerda, e no máximo `Invoices::MAX_CENTS`. Acima disso, a linha sai com erro de valor na prévia.
- **XLSX esparso.** O leitor recusa célula a partir da coluna 200 (`MAX_COLUMNS`) e dados depois da linha 20.000 (`MAX_ROW_INDEX`), com mensagem. `columnIndex` não calcula referências com mais de 3 letras.
- **ZIP.** A leitura das partes do XLSX para em `MAX_UNCOMPRESSED`, de 30 MB, além da checagem pelo tamanho declarado.

## Consequências

- Planilhas reais da administradora ficam bem abaixo dos limites.
- Testes unitários em `ImportsHardeningTest`:
  - valores com teto e sem estouro;
  - `columnIndex`;
  - XLSX esparso recusado e XLSX normal lido.
