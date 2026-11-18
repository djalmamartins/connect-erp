# 0384 — memory_limit de 512M por causa do livro-caixa

- Status: Aceito
- Data: 18/11/2026
- Contexto: continua o ADR 0383 (memória dos PDFs na hospedagem) e o ADR 0362 (limites do PHP no deploy-check).

## Contexto
Medição do PDF do livro-caixa (`CashBookDocument`, Dompdf), com lançamentos pagos no mês e anexos de foto 1600×1200:

| Mês | Memória | Tempo |
| --- | --- | --- |
| 100 lançamentos | 38 MB | 0,8 s |
| 400 lançamentos | 134 MB | 5,7 s |
| 1.000 lançamentos | 547 MB | 22,8 s |
| 60 lançamentos com 60 fotos | 47 MB | 3,7 s |

O movimento do mês é uma tabela só, e o custo do Dompdf numa tabela cresce mais que o número de linhas (ADR 0383). Um
condomínio de 300 unidades com cada taxa recebida como um lançamento passa de 350 linhas por mês. As fotos pesam pouco.

A solução do ADR 0383 (uma tabela por folha, em lotes) não serve aqui sem mudar o documento: o livro-caixa tem resumo,
contas e assinaturas antes e depois do movimento, e as linhas têm altura variável (histórico com conta e banco), então
partir a tabela em blocos faria o título das colunas sumir das folhas do meio ou aparecer no meio da folha.

## Decisão
- `DeployReadiness::REQUIRED_MEMORY_BYTES` passa de 256M para **512M**: o `deploy-check` avisa abaixo disso
  (o hPanel da Cloud Startup permite ajustar em Avançado > Configuração do PHP). HOSPEDAGEM.md §1 atualizado.
- O documento do livro-caixa não muda.

## Consequências
- Com 512M, o livro-caixa aguenta perto de 900 lançamentos no mês; acima disso, só com mais memória ou com o
  livro em partes (decisão futura, se algum condomínio chegar lá).
- O prazo de execução do PHP também conta: 1.000 lançamentos levam ~23 s.
- Testes: `DeployReadinessTest` (256M agora é aviso; 512M e 1G passam).
