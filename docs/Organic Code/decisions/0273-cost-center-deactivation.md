<!-- markdownlint-disable MD024 -->
# 0273 — Inativação em lote dos centros de custo parados

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - cadastro de centros de custo (CN-002), só como cadastro reusado;
  - 0271 e 0272 (encerramento em lote e carteira dos lançamentos fixos);
  - 0267 (inativação em lote dos veículos, de onde vem `VehicleUnitRelease::formError`).

## Contexto

O cadastro de centros de custo (CN-002) agrupa lançamentos por área ou projeto ("Obra do bloco B", "Reforma da piscina").
Terminado o projeto, o centro de custo segue "Ativo":

- entra nas listas do lançamento, da conta bancária e dos lançamentos fixos;
- aparece com zero nos relatórios gerenciais.

Hoje só se inativa um por um, pelo formulário.

Busquei `erp_cost_centers` e `cost_center_id` em `source/`, no README das ADRs e nos handoffs 02xx. Usam o centro de custo
os lançamentos, as contas bancárias (centro de custo padrão), os lançamentos fixos, os saldos de abertura e os relatórios
gerenciais. Nenhum inativa os parados.

## Decisão

Rota nova: `POST /api/v1/cost-centers/deactivate-idle {condominium_id, cost_center_ids?, idle_months?, reason, dry_run?}`,
logo depois de `GET /reports/recurring-portfolio` e antes do laço dos cadastros (`/cost-centers/{id}`). O service é
`CostCenterDeactivation`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada centro de custo é regravado pelo `RegistryService::save` do cadastro "cost_centers", só com a situação
  "Inativo". Valem as regras e a auditoria update do formulário.
- **Erro do formulário antes de gravar:** `VehicleUnitRelease::formError` (a regra pura `RegistryValidator::validate`).
- **Lançamentos, saldos de abertura e lançamentos fixos não mudam.** Nada é apagado e nada é enviado.

### Quais entram

Entram os centros de custo ativos do próprio condomínio sem lançamento com competência a partir de `since_competence`, ou
só os dos `cost_center_ids` informados. Os padrão da Connect (sem condomínio) ficam de fora.

`since_competence` é o mês atual menos `idle_months - 1` (padrão 12 meses, de 1 a 120). Com 12 meses em novembro de 2026,
conta como recente qualquer lançamento a partir de dezembro de 2025.

### Regra pura `since()` e `problem()`

Recusa o centro de custo:

- não encontrado no condomínio (ou padrão da Connect);
- já inativo;
- com lançamento recente;
- com conta em aberto (lançamento ativo sem pagamento): dê baixa ou troque o centro de custo antes;
- usado por lançamento fixo ativo: encerre ou troque antes;
- padrão de uma conta bancária ativa: troque na conta antes;
- com erro no formulário: corrija no cadastro.

Sem ids, os parados com conta em aberto, fixo ativo, conta bancária ou erro no formulário ficam de fora e são contados em
`skipped_open`, `skipped_recurring`, `skipped_bank_default` e `skipped_invalid`. Com ids, um recusado desfaz todos
(`_form` e `_cost_center`).

### Execução

- **`never_used`:** quantos dos inativados nunca tiveram lançamento.
- **Tudo ou nada:** uma transação com os centros de custo travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `deactivate_idle` por centro de custo (última competência com
  lançamento, meses parados e motivo), só quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.cost_centers.view` e `erp.cost_centers.manage`, as do cadastro, e
  `erp.entries.view`, porque o movimento vem dos lançamentos. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou centro de custo recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id e a última competência com lançamento. Não saem código, nome ou observação. Nenhum aviso é
  enviado.
- **Não toca** o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\CostCenterDeactivationTest`:**
  - o início da janela, com a virada do ano e a borda de 120 meses;
  - a regra pura;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - os parados que passam pela recusa (conta cancelada, fixo e conta bancária inativos não contam);
  - as contagens dos que ficam de fora e a janela maior;
  - o contrato de reuso, o controller e a rota.
- **`Integration\CostCenterDeactivationTest`** (MariaDB, banco `*_test`):
  - a prévia e a inativação pelo cadastro do parado e do nunca usado;
  - o com lançamento no mês e o com conta antiga em aberto ficam;
  - o lançamento não muda;
  - as auditorias `deactivate_idle` e update;
  - nenhum aviso e nenhum código ou nome na resposta;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela dos centros de custo pode ganhar o botão
  "Inativar os parados"; quando ganhar, vem o artigo.
