<!-- markdownlint-disable MD024 -->
# 0213 — Ocorrências com o chamado do Help Desk encerrado

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0034 e 0051 (ocorrência → chamado), 0101 (vínculo do chamado), 0142 (conversa do chamado),
  0210 (resumo do síndico)

## Contexto

A ocorrência que precisa da administradora vira chamado no Help Desk (`Occurrences::openTicket`) e passa a "Em
andamento". O Help Desk atende e fecha o chamado, mas nada traz esse desfecho de volta: a ocorrência fica "Em
andamento" no ERP para sempre.

A lista de ocorrências abertas, o painel (ADR 0053) e o resumo do mês para o síndico (ADR 0210) ficam inflados. Hoje,
alguém precisa conferir chamado por chamado e fechar a ocorrência à mão.

## Decisão

Rota nova: `POST /api/v1/occurrences/sync-tickets` com `{condominium_id, include_resolved?, dry_run?}`. O service é
`OccurrenceTicketSync`.

### Reuso, sem consulta paralela ao Help Desk

- **`TicketService::findFor`:** a situação do chamado, com a mesma regra de escopo (chamado do condomínio do usuário).
  Só o protocolo e a situação são usados; nada do solicitante, assunto ou mensagem.
- **`RegistryService::save` do cadastro `occurrences`:** a ocorrência é regravada só com a situação "Resolvida". Valem
  as regras e a auditoria do formulário.

### Regra pura `settles()`

- **Chamado fechado (`closed`):** encerra a ocorrência.
- **Chamado resolvido (`resolved`):** encerra só com `include_resolved`, porque o solicitante ainda pode reabri-lo
  respondendo (ADR 0142).
- **Outras situações** (aberto, em atendimento, aguardando cliente): ficam como estão e vêm contadas em `still_open`,
  pela situação do chamado.
- **Chamado fora do escopo ou inexistente:** conta em `unavailable`.

### Gravação

- **Tudo ou nada:** numa transação com as ocorrências travadas (`FOR UPDATE`), uma recusada pelo formulário desfaz
  todas. O 422 traz os erros e `_occurrence`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `ticket_sync` por ocorrência (protocolo e situação do chamado),
  só quando grava.
- **Limite:** até 500 ocorrências com chamado por vez.
- **Nada muda no Help Desk** e nada é enviado.

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.occurrences.view` e `erp.occurrences.manage`; sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados expostos

Saem id, prioridade, data, a situação anterior, o protocolo e a situação do chamado. Nenhum assunto ou descrição (são
textos livres e podem ter nomes), morador ou solicitante.

## Consequências

- **Testes.**
  - `Unit\OccurrenceTicketSyncTest`:
    - a regra pura para cada situação do chamado, com e sem `include_resolved`;
    - validação e escopo (404);
    - contrato: usa o `findFor` e o `save`; não consulta a tabela do Help Desk; não usa assunto, descrição nem
      solicitante;
    - controller e rota.
  - `Integration\OccurrenceTicketSyncTest` (MariaDB, banco `*_test`):
    - ocorrências com chamado aberto pelo `openTicket` e a situação mudada no Help Desk;
    - prévia sem gravar e sem assunto;
    - a do chamado fechado resolvida, com auditoria `ticket_sync`;
    - a do resolvido só com `include_resolved`;
    - a em atendimento e a sem chamado continuam;
    - o Help Desk sem mudança;
    - escopo.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration, sem tabela nova e nenhum arquivo existente alterado.**
- **Sem tela nova neste patch.** A lista de ocorrências pode ganhar o botão "Conferir chamados"; quando ganhar, vem o
  artigo da Central de Ajuda.
