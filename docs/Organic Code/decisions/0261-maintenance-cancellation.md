<!-- markdownlint-disable MD024 -->
# 0261 — Cancelamento em lote das manutenções agendadas e esquecidas

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0208 (conclusão das manutenções em lote);
  - 0248 (conformidade das manutenções);
  - 0239 e 0255 (mesmo desenho de cancelamento e encerramento em lote);
  - cadastro de manutenções (AP-110), só como cadastro reusado.

## Contexto

O cadastro de manutenções (AP-110) agenda as preventivas e corretivas. A conclusão em lote (ADR 0208) registra as feitas
e agenda a próxima, e a conformidade das manutenções (ADR 0248) conta as atrasadas.

Sobram as agendadas que não vão acontecer: o serviço que mudou de fornecedor e foi cadastrado de novo, a manutenção
duplicada, a obra desistida. Elas ficam "Agendada" para sempre, aparecem como atrasadas e derrubam a taxa de
pontualidade. Hoje cada uma é cancelada à mão.

Busquei `erp_maintenances` em `source/`, no README das ADRs e nos handoffs 02xx. Só a conclusão em lote (0208) muda a
situação em lote, e só para "Concluída". O painel gera a próxima de uma por vez.

## Decisão

Rota nova: `POST /api/v1/maintenances/cancel-overdue {condominium_id, older_than_days?, frequencies?, maintenance_ids?,
reason, dry_run?}`, antes do laço dos cadastros (`/maintenances/{id}`). O service é `MaintenanceCancellation`, em
`Source\Services\Erp`.

### Reuso

- **Gravação:** cada manutenção é regravada pelo `RegistryService::save` do cadastro "maintenances", só com a situação
  "Cancelada". Valem as regras e a auditoria update do formulário.
- **Mesmo desenho** dos ADRs 0239 e 0255, com a regra de fornecedor ativo e documento no Disco Virtual.
- **Periodicidades:** as do cadastro.
- **Sem efeito colateral:** nada é apagado, nenhuma próxima é agendada e nada é enviado.

### Quais entram

Entram as manutenções agendadas do condomínio:

- com a data prevista há mais de `older_than_days` dias (padrão 60, de 15 a 3650);
- das periodicidades escolhidas (padrão todas);
- ou só as dos `maintenance_ids` informados.

### Regra pura `problem()`

Recusa a manutenção:

- não encontrada no condomínio;
- em execução: conclua pelo formulário ou pela conclusão em lote;
- já concluída ou cancelada;
- ainda dentro do prazo;
- de fornecedor inativo ou com o documento removido: o formulário não regrava.

Sem ids, as do último caso ficam de fora e a contagem sai em `skipped_inactive_link`. Com ids, uma recusada desfaz todas
(`_form` e `_maintenance`).

### Execução

- **`periodic`:** conta as canceladas que são periódicas. A próxima não é agendada, e o calendário dessas deve ser
  revisto no cadastro.
- **Tudo ou nada:** uma transação com as manutenções travadas (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `cancel_overdue` por manutenção (periodicidade, data prevista, dias
  de atraso e motivo), só quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.maintenances.view` e `erp.maintenances.manage`, as do cadastro. Sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou manutenção recusada, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, periodicidade, data prevista e dias de atraso. Não saem serviço, fornecedor, descrição ou
  documento. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\MaintenanceCancellationTest`:**
  - a regra pura;
  - a validação (prazo, periodicidades, motivo, ids) e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens das que ficam de fora sem ids e o filtro por periodicidade;
  - o contrato de reuso, o controller e a rota.
- **`Integration\MaintenanceCancellationTest`** (MariaDB, banco `*_test`):
  - a prévia e a gravação pelo cadastro;
  - a recente, a em execução e a de fornecedor inativo ficam como estão;
  - nenhuma próxima agendada;
  - as auditorias `cancel_overdue` e update;
  - nenhum aviso e nenhum serviço ou fornecedor na resposta;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A tela de manutenções pode ganhar o botão "Cancelar as esquecidas"; quando ganhar, vem o
  artigo da Central de Ajuda.
