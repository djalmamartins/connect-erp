# 0171 — Importação de lançamentos patrimoniais

Data: 2026-11-10 · Status: proposto para revisão · Pendência do ADR 0169

## Contexto

Os lançamentos patrimoniais (ADR 0169) só entravam um a um pela API. As importações do ERP (`Imports`, ADR 0058) têm um fluxo de prévia com
erro por linha e uma aplicação tudo ou nada. Esse fluxo é amarrado a `Imports::KINDS`, que exige tela própria por tipo
(`ErpImportScreensTest`), e ao enum `erp_imports.kind`.

## Decisão

- **Service separado**, `Imports\PatrimonialImports`, com o mesmo fluxo e o mesmo formato:
  - **prévia**: devolve cada linha com `line`, `status` e `errors`, além de `rows_count`, `error_count` e `summary`;
  - **aplicação**: revalida o conteúdo guardado e grava tudo numa transação;
  - **leitura do arquivo**: o mesmo leitor (`SpreadsheetReader`, CSV ou XLSX de até 2 MB) e as mesmas regras de
    valor e data (`ImportRules`).
  - O tipo não entra em `Imports::KINDS`, para não exigir tela nova (proibida neste ciclo) e não mexer no `Imports.php` nem no enum.
- **Colunas**:
  - obrigatórias: `data`, `descricao`, `valor`, `conta_debito` e `conta_credito` (código do plano);
  - opcionais: `conta_bancaria` (id ou nome) e `lancamento_origem` (id do lançamento).
- **Validação por linha**, igual à do `PatrimonialEntries`:
  - contas patrimoniais analíticas ativas, do padrão ou do condomínio, e diferentes entre si;
  - nunca as calculadas (7.1, 7.2, 8.1);
  - valor positivo;
  - data até hoje, em competência aberta;
  - conta bancária e lançamento de origem do mesmo condomínio.
  - Na gravação, cada linha passa pelo próprio `PatrimonialEntries::create`, que valida de novo e audita.
- **Tudo ou nada**:
  - a aplicação marca a importação como aplicada (UPDATE condicional, contra aplicação dupla), revalida e grava as
    linhas na mesma transação;
  - qualquer erro, inclusive um mês fechado depois da prévia, desfaz tudo e responde 409;
  - o lançamento guarda `import_id`.
- **Idempotência**:
  - a chave (`Idempotency-Key` ou `idempotency_key`) é única por condomínio; sem ela, vale `sha256:<hash do arquivo>`;
  - o mesmo arquivo em prévia é revalidado no mesmo registro;
  - depois de aplicado, uma nova prévia ou aplicação responde 409;
  - a mesma chave com outro arquivo responde 409.
- **Migration 20261109_0708** (com down):
  - cria `erp_patrimonial_imports`, com o conteúdo guardado na linha, para a aplicação ver exatamente o que a prévia viu;
  - cria `erp_patrimonial_entries.import_id`.
- **API**:
  - `POST /api/v1/patrimonial-imports` para a prévia;
  - `GET /api/v1/patrimonial-imports/{id}`;
  - `POST /api/v1/patrimonial-imports/{id}/apply`.
  - Permissões das importações: `erp.imports.view`; para prévia e aplicação, também `erp.imports.manage` e `erp.entries.manage`.
  - Escopo por condomínio: fora, 404. Inválido, 422. Conflito, 409. O CSRF fica no `ApiAuthenticated`.
- **Sem tela nova**: a tela fica para a frente de layout. Ela pode reaproveitar `pages/imports/import` e registrar o tipo no
  `ImportScreens`. Até lá não há página no catálogo nem artigo na Central de Ajuda.

## Consequências

- `PatrimonialImportsTest` (4 testes) cobre:
  - a prévia com o erro de cada linha (conta calculada, conta de receita, contas iguais, data futura, valor, banco e
    origem de outro condomínio), sem gravar nada;
  - a aplicação com as duas linhas, `import_id` e o balanço fechado;
  - o tudo ou nada com a competência fechada depois da prévia;
  - a idempotência pelo arquivo e pela chave;
  - o escopo, o 404, a validação 422 e as permissões no controller.

## Nota da integração na main

A competência fechada é conferida por `RegistryService::isClosed`, e não por uma consulta própria. Dentro da transação do `apply()`, essa leitura é travada (ADR 0100, só no MariaDB), então o mês não pode ser fechado enquanto o lote está sendo gravado.
