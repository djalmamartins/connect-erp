# 0062 — Recibo sem oráculo de id, teto nos valores e limite de unidades por cobrança

- Status: aceito (09/11/2026)
- Contexto: revisão de segurança do ciclo 24 (`docs/security/REVIEW_API_FINANCEIRO.md`, terceira rodada), ADR 0057 e a correção dos acordos na primeira rodada

## Contexto

A revisão dos lançamentos avulsos e do recibo (ADR 0057) encontrou três pontos.

1. **Recibo com oráculo de id.** `UnitDocuments::receipt` buscava as contas por `WHERE id IN (...) FOR UPDATE` sem filtrar o condomínio. Com isso:
   - um id de outro condomínio respondia "O recibo só cobre contas a receber válidas desta unidade";
   - um id inexistente respondia "Alguma conta escolhida não existe".

   Era o mesmo oráculo de enumeração já corrigido nos acordos, e a transação travava linhas de outro condomínio.
2. **Valor sem teto em `Invoices::cents`.**
   - Um texto com 20 dígitos estourava o inteiro do PHP. O resultado virava float, e o retorno `?int` com `strict_types` dava TypeError, ou seja, erro 500.
   - Um inteiro acima do que a coluna aceita passava pela validação.

   `cents()` é usada por faturas, desconto, cobrança extra, multa e transferência. O motor de cadastros já tinha teto (`99_999_999_999` centavos).
3. **Cobrança extra sem limite de unidades.** A lista `unit_ids` ia inteira para o `IN (...)` e virava uma conta a receber por unidade, numa só transação.

## Decisão

- O recibo busca só as contas do condomínio da unidade (`AND e.condominium_id = :c`). Um id de outro condomínio responde igual a um inexistente e não é travado.
- `Invoices::MAX_CENTS = 99_999_999_999`, o mesmo teto do `RegistryValidator`. Acima disso, `cents()` devolve `null`, e a mensagem de valor inválido de cada serviço aparece com 422. Um texto com mais de 10 dígitos na parte inteira é recusado antes da conversão para inteiro.
- `EntryShortcuts::unitIds()` normaliza a lista (inteiros positivos, sem repetição) e aceita no máximo `MAX_UNITS = 1000` unidades por cobrança.

## Consequências

- Nenhum valor real de condomínio chega perto do teto, que é de quase R$ 1 bilhão. O efeito é trocar um 500 por uma mensagem de validação.
- Testes:
  - unitários: `InvoicesTest::testCentsHasCeilingAndNeverOverflows` e `EntryShortcutsTest::testUnitIdsAreNormalizedAndCapped`;
  - integração (lab): `EntryShortcutsIntegrationTest::testReceiptTreatsOtherCondominiumEntryAsMissing`.
