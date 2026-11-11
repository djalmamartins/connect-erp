<!-- markdownlint-disable MD024 -->
# 0209 — Nova conferência do extrato pendente

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0058 e 0074 (importação do extrato e conciliação), AP-140, 0193/0194 (travas de conciliação)

## Contexto

A importação do extrato (OFX) concilia na hora cada linha com o lançamento pago ou a transferência da conta, pela regra
pura `ImportRules::match`:

- mesmo sentido;
- mesmo valor;
- até 3 dias de diferença;
- exatamente um candidato.

No dia a dia da administradora, o extrato costuma ser importado **antes** da baixa. O morador pagou, o banco creditou, e
a conta a receber só é baixada depois (pelo retorno, em lote, à mão). Essas linhas ficam pendentes para sempre: a
importação não roda de novo e a conciliação à mão (`resolveLine`) é uma por uma.

O Balanço e a conferência antes de fechar o mês (ADR 0181) passam a apontar extrato sem conciliação, mesmo com tudo
baixado.

## Decisão

Rota nova: `POST /api/v1/bank-statement-lines/rematch` com `{condominium_id, bank_account_id?, dry_run?}`. O service é
`StatementRematch`.

### Conferência

Roda de novo, sobre as linhas ainda pendentes de uma conta bancária (ou de todas as ativas do condomínio), o mesmo
cruzamento da importação:

- **Regra:** a mesma `ImportRules::match`, com as mesmas consultas de candidatos (lançamentos pagos na conta e
  transferências da conta, ainda não conciliados, perto das datas das linhas).
- **Sem escolha automática:** linha com nenhum ou mais de um candidato continua pendente; a conciliação à mão segue
  valendo para ela.
- **Fora da conferência:** linhas ignoradas ou já conciliadas, e lançamentos cancelados, excluídos ou ainda não pagos.

### Gravação

- **Transação:** numa transação com as linhas travadas (`FOR UPDATE`). O `UPDATE` tem a mesma condição de pendente, então
  o que outra pessoa resolveu no meio não é sobrescrito.
- **O que grava:** a linha conciliada ganha o lançamento ou a transferência, uma nota fixa, quem e quando.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** `update` por linha conciliada (pendente → conciliada, regra `rematch`), só quando grava.
- **Limite:** até 2000 linhas pendentes por conta (acima disso, 422 pedindo conta por conta).
- **Nada vai para o banco** e nada é enviado.

### Acesso

- **Escopo (ADR-002):** condomínio do escopo; fora dele, 404. Conta bancária de outro condomínio também dá 404.
- **Permissão:** as mesmas da conciliação à mão: `erp.access`, `erp.imports.view`, `erp.imports.manage` e
  `erp.entries.manage`. Sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados expostos

Saem ids, datas, valores, sentido e as contas. O histórico (memo) do banco não sai, porque pode trazer o nome de quem
pagou.

## Consequências

- **Testes.**
  - `Unit\StatementRematchTest` (SQLite, a lógica inteira, sem o `FOR UPDATE`):
    - lançamento pago depois casa;
    - transferência casa nas duas contas;
    - dois candidatos, fora dos 3 dias, lançamento já conciliado, cancelado, excluído e não pago continuam pendentes;
    - ignorada não muda e outro condomínio não muda;
    - prévia, repetição, filtro por conta, validação, conta de outro condomínio, escopo e contrato.
  - `Integration\StatementRematchTest` (MariaDB, banco `*_test`):
    - extrato importado antes das baixas;
    - a linha com um candidato conciliada, com a nota e o usuário;
    - a com dois candidatos e a fora do prazo pendentes;
    - auditoria;
    - memo fora da resposta;
    - escopo.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Sem tela nova neste patch.** A tela de conciliação (AP-140) pode ganhar o botão "Conferir de novo"; quando ganhar,
  vem o artigo da Central de Ajuda.
