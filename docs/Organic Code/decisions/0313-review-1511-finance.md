# 0313 — Revisão de 15/11, etapa 4: fechamento mensal em ordem

- Status: Aceito (parte 1)
- Data: 16/11/2026
- Issue: #543 (revisão de 15/11, etapa 4)
- Relacionadas: ADR 0031 (fechamento mensal), 0100 (concorrência), 0181 (conferência antes de fechar), 0205 (fechamento em lote)

## Contexto

Na revisão de 15/11 o Djalma pediu que o fechamento mensal siga a ordem: não fechar um mês se houver um anterior
aberto, com alerta, para as pendências não virarem bola de neve. Até aqui a conferência (ADR 0181) só avisava sobre o
mês imediatamente anterior, e o fechamento não recusava nada.

## Decisão

1. **Mês anterior em aberto** = competência antes da pedida, no mesmo condomínio, que tem lançamento válido (ativo e
   não excluído) ou fechamento gravado, e não está fechada (aberta ou reaberta). Mês sem lançamento e sem fechamento não
   conta, para o condomínio que começou a usar o sistema no meio do ano não ficar travado.
   INFERIDO: o Djalma não disse o que fazer com mês vazio; ficou fora porque não há o que fechar.
2. **Fechar recusa** (`MonthClosing::close`, 409 na API e mensagem na tela) com a lista dos meses pendentes, do mais
   antigo para o mais novo. A recusa não grava nada. Vale também para o fechamento em lote (ADR 0205), que chama o mesmo
   serviço.
3. **Conferência** (ADR 0181): `previous_open` passou de aviso a erro e olha todos os meses anteriores, não só o último.
4. **Alerta na tela** (AP-030): com um condomínio, alerta vermelho com o link de cada mês pendente e o botão Fechar
   travado; em "Todos", alerta com os condomínios que têm mês anterior aberto e os meses na coluna Situação.
   `GET /api/v1/closings` devolve `pending_before`.
5. **Reabrir** continua livre (com motivo): o mês reaberto volta a contar como pendente para os meses seguintes que
   ainda não foram fechados. Os meses seguintes já fechados continuam fechados.

## Testes

- `tests/legacy/Integration/MonthClosingIntegrationTest.php`: meses pendentes por condomínio, mês vazio fora, recusa
  sem gravar, fechar na ordem e mês reaberto pendente.
- `tests/legacy/Unit/ClosingReadinessTest.php`: `previous_open` como erro, com o mês na mensagem.
- Render smoke: alerta com link e botão travado, sem alerta quando não há pendência, e a visão "Todos".
