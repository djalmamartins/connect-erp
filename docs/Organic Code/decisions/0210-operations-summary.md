<!-- markdownlint-disable MD024 -->
# 0210 — Resumo operacional do mês para o síndico (e o vínculo da próxima manutenção)

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0051 e 0053 (cadastros operacionais e `OperationalDashboard`), 0208 (conclusão de manutenções),
  ADR-002 (escopo)

## Contexto

O síndico pede todo mês o resumo da operação do condomínio, para a reunião do conselho ou para a prestação de contas:

- quantas ocorrências abriram e quais seguem abertas, há quanto tempo;
- quais manutenções foram feitas e quais estão atrasadas;
- quanto o salão de festas foi usado.

Hoje isso está em três telas (Ocorrências, Manutenções, Reservas), e a administradora monta o resumo à mão. O painel do
`OperationalDashboard` (ADR 0053) mostra só cartões do momento, sem o mês.

Ao revisar o painel para este ciclo, achei também um ponto do ADR 0208. O `OperationalDashboard::nextMaintenance` (ADR
0053) já gera a próxima manutenção de uma concluída e a liga a ela por `previous_id`, que é único e impede gerar duas
vezes. A conclusão em lote do ADR 0208 criava a próxima **sem** esse vínculo. Assim, o botão do painel ainda podia
gerar uma segunda próxima para a mesma manutenção.

## Decisão

### 1. Resumo operacional do mês

Rota nova, somente leitura: `GET /api/v1/reports/operations-summary?condominium_id=&competence=AAAA-MM`. O service é
`OperationsSummary`.

**No mês**, pelas datas do próprio registro:

- ocorrências abertas (`occurred_on`), por prioridade;
- manutenções previstas (`planned_on`, sem as canceladas) e feitas (`done_on`);
- reservas confirmadas (`reserved_on`), por área, com as taxas.

**Hoje.** O cadastro guarda a situação de agora, não o histórico, por isso o pendente é "hoje" e não "no fim do mês":

- ocorrências abertas ou em andamento, por prioridade e por idade (regra pura `ageBucket`: 0-7, 8-30, 31+ dias desde a
  ocorrência);
- manutenções atrasadas, listadas pelo serviço com os dias de atraso (até 50, com `truncated`), e as dos próximos 30
  dias;
- reservas aguardando aprovação.

**Dados e acesso:**

- Saem só contagens, serviços, nomes de área e taxas. Nenhum assunto ou descrição de ocorrência (são textos livres e
  podem ter nomes), nenhum morador, nenhuma unidade.
- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.occurrences.view`, `erp.maintenances.view` e `erp.reservations.view`. Sem
  uma delas, 403, em vez de um resumo pela metade (fail-closed).
- Não grava e não envia nada.

### 2. A próxima manutenção ligada à concluída (correção do ADR 0208)

O `MaintenanceCompletion` (ADR 0208) passa a usar o mesmo vínculo do painel:

- **Já gerada:** se a concluída já tem uma próxima ligada (`previous_id`), ela vale, na data dela
  (`already_scheduled`).
- **Agendada sem vínculo:** se a mesma manutenção já está agendada na data e sem vínculo, ela é ligada à concluída.
- **Criada agora:** a próxima criada recebe `previous_id` (o índice único protege contra corrida).

Com isso, o botão "Gerar próxima" do painel responde "A próxima manutenção desta já foi gerada." para uma manutenção
concluída em lote.

## Consequências

- **Testes.**
  - `Unit\OperationsSummaryTest` (SQLite, a lógica inteira):
    - mês e hoje;
    - excluídas, canceladas e outro condomínio fora;
    - a de hoje entre as dos próximos 30 dias, não atrasada;
    - atrasadas em ordem;
    - reservas por área com taxas e pendentes futuras;
    - nenhum assunto, morador ou unidade na resposta;
    - regras puras, validação, escopo e contrato (somente leitura).
  - `Integration\OperationsSummaryTest` (MariaDB, banco `*_test`):
    - ocorrências e manutenções gravadas pelos cadastros, reservas reais;
    - nenhum dado pessoal;
    - nenhuma auditoria nova;
    - escopo.
  - `Integration\MaintenanceCompletionTest` (ampliado): a próxima criada e a já agendada ficam ligadas, e o
    `OperationalDashboard::nextMaintenance` recusa gerar de novo para as duas. O `Unit\MaintenanceCompletionTest` confere
    o vínculo no service.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova:** `previous_id` já existe (20261108_0531).
- **Sem tela nova neste patch.** O resumo pode virar a página "Resumo do mês" do síndico; quando virar, vem o artigo da
  Central de Ajuda.
