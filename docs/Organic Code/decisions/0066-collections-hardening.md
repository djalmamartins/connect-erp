# 0066 — Cobrança e multas: contas do caso no escopo da unidade, trava no andamento e nome do contato limpo

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (quinta rodada)

## Contexto

A revisão de segurança da cobrança e das multas (ADR 0080) conferiu escopo, permissões, a regra de
que o caso judicial não envia nem lança nada e as transições de situação. Ficaram três pontos.

## Decisão

- **Contas do caso no escopo da unidade.** A abertura do caso buscava as contas só pelo id, com
  `FOR UPDATE`, e só depois conferia a unidade. Uma conta de outro condomínio dava uma mensagem
  diferente da conta inexistente, o que revelava que o id existe, e ficava travada até o fim da
  transação. Agora a busca já filtra pela unidade e pelo condomínio, e qualquer conta de fora
  responde "não existe nesta unidade". A lista passa por `Collections::entryIds()`: inteiros
  positivos, sem repetição, de 1 a `MAX_CASE_ENTRIES` (200), conferidos antes de qualquer conversão.
- **Trava no andamento.** `addEvent` lia a situação fora da transação. Dois andamentos ao mesmo tempo
  podiam passar pela regra de transição a partir da mesma situação, e um deles reabria um caso que o
  outro tinha encerrado. Agora a situação é relida com `FOR UPDATE` dentro da transação. Se mudou,
  a resposta é 409 e nada é gravado.
- **Nome do contato.** O nome de quem atendeu passa a ser uma linha só, sem caracteres de controle,
  com até 160 caracteres.

## Consequências

- `CollectionsTest::testEntryIdsAreValidatedAndCapped` e `CollectionsCycleIntegrationTest` cobrem
  as três regras.
