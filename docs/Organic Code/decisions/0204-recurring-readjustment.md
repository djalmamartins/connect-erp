# 0204 — Reajuste de lançamentos fixos em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0027 (lançamentos fixos), 0197 e 0203 (operações em lote pelo formulário)

## Contexto

Todo ano, e a cada aniversário de contrato, a administradora reajusta os contratos fixos dos condomínios: portaria,
limpeza, elevador, administração e honorários. O índice muda de contrato para contrato (IPCA, IGP-M, dissídio da
categoria). Hoje cada lançamento fixo é aberto e tem o valor recalculado à mão, o que leva a erro de conta e a
esquecimento. Além disso, o mês já gerado com o valor antigo passa despercebido.

## Decisão

Rota nova: `POST /api/v1/recurring-entries/readjust` com
`{condominium_id, recurring_ids[], percent, from_competence, reason, dry_run?}`. O service é `RecurringReadjustment`.
A rota fica antes do laço dos cadastros, porque `/recurring-entries/{id}` capturaria "readjust".

### Cálculo

- **Percentual** (regra pura `basisPoints`): de −50% a 100%, com até 2 casas (vírgula ou ponto) e diferente de zero.
  É guardado em centésimos de ponto.
- **Valor novo** (regra pura `readjusted`): centavos × (1 + percentual), arredondado ao centavo (meio para cima), em
  aritmética inteira, sem `float`.

### Recusas

A regra pura `problem` recusa o modelo:

- não encontrado no condomínio (ou excluído);
- inativo;
- encerrado antes da competência do reajuste.

As regras do formulário também valem, porque cada modelo é regravado pelo `RegistryService::save` do cadastro
`recurring_entries` só com o valor trocado: conta, fornecedor e conta bancária ativos, e conta da natureza certa.

### Lançamentos já gerados

O que já foi gerado não muda. Mexer neles seria uma ação em lote sobre lançamentos, e cada conta pode já estar em
boleto ou em recibo. Os lançamentos em aberto da competência em diante vêm por modelo em `already_generated`
(quantidade e valor), com o aviso `already_generated`, para a conferência. Os próximos meses nascem com o valor novo,
porque `RecurringEntries::generate` lê o modelo.

### Gravação

- **Tudo ou nada:** numa transação com os modelos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz os
  erros e `_recurring`.
- **Prévia:** `dry_run` passa por tudo, inclusive pelo formulário, e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `readjust` por modelo, com o valor antes e depois, o
  percentual, a competência e o motivo.
- **Limites:** um condomínio do escopo (fora dá 404); até 200 modelos; motivo obrigatório (5 a 255 caracteres).

### Acesso

- **Permissão:** exige `erp.access`, `erp.recurring_entries.view` e `erp.recurring_entries.manage`; sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Consultas:** parametrizadas.

## Consequências

- **Testes.**
  - `Unit\RecurringReadjustmentTest` (SQLite):
    - percentual (formatos aceitos e recusados);
    - arredondamento, inclusive o meio centavo, desconto e valor grande;
    - recusas e ids;
    - validação, escopo (404), controller, transação, auditoria e rota.
  - `Integration\RecurringReadjustmentTest` (MariaDB, banco `*_test`):
    - prévia sem gravar;
    - reajuste de 4,62% (100,00 → 104,62; 123,45 → 129,15);
    - o mês já gerado sem mudar e listado no aviso;
    - o mês seguinte gerado com o valor novo;
    - auditorias `readjust`;
    - recusa de inativo, encerrado e inexistente, com tudo ou nada.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Sem tela nova neste patch.** Quando a tela existir (botão "Reajustar" em Lançamentos fixos), ela ganha o artigo da
  Central de Ajuda.
