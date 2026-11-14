# 0305 — Toda ocorrência é um chamado do Help Desk

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0034 (todo chamado no Help Desk), 0051 (ocorrência → chamado), 0142 (conversa no ERP),
  0213 (sincronizar ocorrências com chamado fechado), 0255/0256 (ocorrências sem chamado), 0302/0303 (app do morador)
- **Numeração:** faixa avulsa 0300–0319.

## Contexto

Pedido do Djalma (14/11/2026): "toda ocorrência deveria ser tratada como um chamado", num lugar só, para não ter
vários lugares para verificar. Até aqui a ocorrência (AP-112) era um cadastro à parte e o chamado era opcional
(botão "Abrir chamado"); a situação da ocorrência não acompanhava o Help Desk sem a sincronização em lote (0213).

## Decisão

- **Nasce junto:** o cadastro `occurrences` tem `helpdesk_ticket`. Ao criar (formulário do ERP, API
  `/api/v1/occurrences`, app do morador), `RegistryService` abre o chamado por `Occurrences::openForNew` na mesma
  transação (`TicketService::open` entra na transação de quem chama). Sem chamado, sem ocorrência: o erro volta como
  validação do formulário. Origem `erp`, ou `residents` com o morador como solicitante.
- **Situação segue o chamado:** `Occurrences::followTickets` roda depois de cada mudança de situação no Help Desk
  (alterar, em lote, responder) e na resposta do solicitante. aberto = Aberta; em atendimento/aguardando cliente =
  Em andamento; resolvido/fechado = Resolvida. No formulário, trocar a situação de uma ocorrência com chamado para
  algo diferente do chamado é recusado (`statusLockMessage`).
- **Ocorrências antigas:** a migration `20261114_1000_occurrence_tickets` cria o chamado de cada ocorrência sem
  chamado, com protocolo `OC` + número da ocorrência (10 dígitos), situação equivalente (resolvida = fechado, fora da
  fila), sem enviar aviso. Idempotente; a reversão apaga só os chamados `OC...`.
- **Lugar único:** Operacional › Chamados ganha o filtro Tipo (só ocorrências / sem ocorrências) e o selo
  "Ocorrência #id"; `GET /api/v1/tickets?kind=occurrence|other` e `occurrence_id` no chamado.
- **App do morador:** `GET/POST /api/v1/resident/tickets`, `GET /api/v1/resident/tickets/{id}` (conversa pública,
  nunca nota interna) e `POST /api/v1/resident/tickets/{id}/messages` (fechado = 409 `ticket_closed`). Só os chamados
  que o próprio morador pediu, no condomínio do vínculo, com a função "occurrences" ligada. `protocol` em
  `GET /api/v1/resident/occurrences`.

## Consequências e lacunas

- Os relatórios de ocorrências sem chamado (0255, 0256) passam a ficar vazios; ficam como estão por compatibilidade.
- Mudança de assunto/descrição da ocorrência depois de criada não vai para o chamado.
- Telas do app nativo ficam com a thread do app (contrato acima).
