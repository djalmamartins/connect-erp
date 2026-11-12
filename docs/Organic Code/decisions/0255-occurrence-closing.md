<!-- markdownlint-disable MD024 -->
# 0255 — Encerramento em lote das ocorrências antigas sem chamado

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - 0213 (ocorrências com o chamado do Help Desk encerrado, mesmo desenho);
  - 0239 (cancelamento em lote dos empenhos antigos, mesmo desenho);
  - cadastro de ocorrências (AP-112), só como cadastro reusado.

## Contexto

O cadastro de ocorrências (AP-112) registra barulho, vazamento e dano. O relatório de ocorrências e o resumo do síndico
contam as abertas e as em andamento.

As que viraram chamado se acertam pela sincronização com o Help Desk (ADR 0213). As que nunca viraram chamado ficam
"Aberta" ou "Em andamento" para sempre quando ninguém volta ao formulário: o vazamento consertado, o barulho que passou.
Elas inflam a lista, o painel e o resumo, e hoje cada uma é resolvida à mão.

Busquei `erp_occurrences` em `source/`, no README das ADRs e nos handoffs 02xx. Só a sincronização com o Help Desk muda a
situação em lote, e só das ocorrências com chamado.

## Decisão

Rota nova: `POST /api/v1/occurrences/close-stale {condominium_id, older_than_days?, priorities?, occurrence_ids?, reason,
dry_run?}`. O service é `OccurrenceClosing`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada ocorrência é regravada pelo `RegistryService::save` do cadastro "occurrences", só com a situação
  "Resolvida". Valem as regras e a auditoria update do formulário.
- **Mesmo desenho** da sincronização com o Help Desk (ADR 0213) e do cancelamento em lote dos empenhos (ADR 0239).
- **Prioridades:** as do cadastro.

### Quais entram

Entram as ocorrências abertas ou em andamento do condomínio, sem chamado:

- com a data há mais de `older_than_days` dias (padrão 90, de 30 a 3650);
- das prioridades escolhidas (padrão todas);
- ou só as dos `occurrence_ids` informados.

### Regra pura `problem()`

Recusa a ocorrência:

- não encontrada no condomínio;
- já resolvida;
- com chamado no Help Desk (acerta pela sincronização);
- ainda dentro do prazo;
- de unidade inativa ou com o documento removido (o formulário não regrava).

Sem ids, as de chamado ficam de fora e são contadas em `skipped_with_ticket`; as de unidade inativa ou documento removido,
em `skipped_inactive_link`. Com ids, uma recusada desfaz todas (`_form` e `_occurrence`).

### Execução

- **Tudo ou nada:** uma transação com as ocorrências travadas (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `close_stale` por ocorrência (situação anterior, prioridade, data,
  dias em aberto e motivo), só quando grava. O motivo é obrigatório.
- **Nada muda no Help Desk.**

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.occurrences.view` e `erp.occurrences.manage`, as do cadastro. Sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou ocorrência recusada, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, prioridade, situação anterior, data e dias em aberto. Não saem assunto, descrição, unidade ou
  documento. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\OccurrenceClosingTest`:**
  - a regra pura;
  - a validação (prazo, prioridades, motivo, ids) e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens das que ficam de fora sem ids e o filtro por prioridade;
  - o contrato de reuso, o controller e a rota.
- **`Integration\OccurrenceClosingTest`** (MariaDB, banco `*_test`):
  - a prévia e a gravação pelo cadastro, abertas e em andamento;
  - a recente e a de unidade inativa ficam como estão;
  - as auditorias `close_stale` e update;
  - nenhum aviso e nenhum assunto ou descrição na resposta;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A lista de ocorrências pode ganhar o botão "Encerrar as antigas"; quando ganhar, vem o
  artigo da Central de Ajuda.
