<!-- markdownlint-disable MD024 -->
# 0271 — Encerramento em lote dos lançamentos fixos que já acabaram

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0027 (geração dos lançamentos fixos do mês);
  - 0204 (reajuste dos lançamentos fixos, mesmo desenho de regravação);
  - 0245 (encerramento dos contratos, que só aponta os fixos para revisão);
  - inativação em lote dos fornecedores, que recusa fornecedor com lançamento fixo ativo;
  - cadastro de lançamentos fixos (AP-021), só como cadastro reusado.

## Contexto

O cadastro de lançamentos fixos (AP-021) tem a "Última competência". A geração do mês (ADR 0027) e o reajuste (ADR 0204)
já ignoram o modelo encerrado, mas a situação segue "Ativo" até alguém abrir o formulário.

O modelo encerrado em março continua na lista dos ativos. Enquanto estiver ativo, a inativação em lote dos fornecedores
recusa o fornecedor ("encerre a recorrência antes"). O encerramento dos contratos (ADR 0245) só aponta os fixos para
revisão (`recurring_to_review`).

Busquei `erp_recurring_entries` em `source/`, no README das ADRs e nos handoffs 02xx. Usam a tabela a geração, o reajuste,
a previsão de caixa, o encerramento dos contratos e a inativação dos fornecedores. Nenhum muda a situação dos modelos que
acabaram.

## Decisão

Rota nova: `POST /api/v1/recurring-entries/close-ended {condominium_id, recurring_ids?, before_competence?, reason,
dry_run?}`, logo depois de `GET /reports/vacant-units` e antes do laço dos cadastros (`/recurring-entries/{id}`). O
service é `RecurringClosing`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada modelo é regravado pelo `RegistryService::save` do cadastro "recurring_entries", só com a situação
  "Inativo". O valor vai em reais e os demais campos como estão, como no reajuste (0204). Valem as regras e a auditoria
  update do formulário.
- **Erro do formulário antes de gravar:** `VehicleUnitRelease::formError` (a regra pura `RegistryValidator::validate`),
  só com o rótulo do campo e a mensagem.
- **Vínculos:** a conta, o centro de custo, a conta bancária e o fornecedor precisam estar ativos, como pede o formulário.
- **Competência:** `RecurringEntries::isCompetence`.
- **Lançamentos já gerados não mudam.** Nada é apagado e nada é enviado.

### Quais entram

Entram os modelos ativos do condomínio com a última competência antes de `before_competence` (padrão o mês atual, nunca
depois dele), ou só os dos `recurring_ids` informados.

### Regra pura `problem()`

Recusa o modelo:

- não encontrado no condomínio;
- já inativo;
- sem última competência (sem fim): informe no formulário;
- com a última competência ainda por vir (o próprio mês vale);
- com conta, centro de custo, conta bancária ou fornecedor inativo: o formulário não regrava;
- com erro no formulário: corrija no cadastro.

Sem ids, os dois últimos casos ficam de fora e são contados em `skipped_inactive_link` e `skipped_invalid`. Com ids, um
recusado desfaz todos (`_form` e `_recurring`).

### Execução

- **`monthly_cents`:** o valor mensal dos encerrados, por natureza (receita e despesa).
- **`suppliers_freed`:** os fornecedores dos encerrados que ficam sem nenhum lançamento fixo ativo e podem ser inativados
  em lote.
- **Tudo ou nada:** uma transação com os modelos travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `close_ended` por modelo (última competência, valor e motivo), só
  quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.recurring_entries.view` e `erp.recurring_entries.manage`, as do cadastro. Sem uma
  delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou modelo recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, natureza, última competência e valor. Não saem descrição, fornecedor, conta ou observação.
  Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\RecurringClosingTest`:**
  - a regra pura, com o próprio mês ainda valendo;
  - a validação (competência de corte até o mês atual) e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens dos que ficam de fora (fornecedor inativo, conta excluída, dia do vencimento fora de 1 a 28);
  - a competência de corte anterior;
  - o contrato de reuso, o controller e a rota.
- **`Integration\RecurringClosingTest`** (MariaDB, banco `*_test`):
  - a prévia e o encerramento pelo cadastro do modelo que acabou;
  - o sem fim e o que termina no mês ficam;
  - o valor não muda;
  - o fornecedor liberado;
  - as auditorias `close_ended` e update;
  - nenhum aviso e nenhum dado na resposta;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela dos lançamentos fixos pode ganhar o botão
  "Encerrar os que já acabaram"; quando ganhar, vem o artigo.
