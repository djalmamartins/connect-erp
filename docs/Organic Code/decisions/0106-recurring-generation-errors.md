# 0106 — Geração dos lançamentos fixos não deixa transação aberta

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (15ª rodada)

## Contexto

`RecurringEntries::generate` (ADR 0027) abre uma transação por modelo, mas só trata dois tipos de
erro: `RegistryValidationException` (modelo vai para `failed`) e `PDOException` (1062 = gerado em
paralelo). Qualquer outro erro subia **com a transação aberta**. Os modelos anteriores já estavam
gravados, e a mesma conexão seguia com a transação pendente. Um exemplo é o `InvalidArgumentException`
de `dueDate`, com um dia fora de 1 a 28 gravado direto no banco ou por importação antiga.

## Decisão

- `InvalidArgumentException` e `DomainException` desfazem a transação do modelo e o põem em `failed`,
  com a mensagem em `_form`. Os outros modelos continuam sendo gerados.
- Qualquer outro erro desfaz a transação antes de subir.

## Consequências

- A resposta de `POST /api/v1/recurring-entries/generate` não muda de formato: `failed` já era um mapa
  modelo → erros.
- Teste: `RecurringEntriesTest::testTemplateWithInvalidDayFailsWithoutLeavingTransactionOpen`. Ele roda
  só no Mac, porque o CI roda apenas a suíte unitária.
