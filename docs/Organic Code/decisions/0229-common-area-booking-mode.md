<!-- markdownlint-disable MD024 -->
# 0229 — Bloqueio e liberação em lote das áreas comuns

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-008 (cadastro das áreas comuns) e ADR 0051 (regras da área na reserva), só como fonte e regra reusadas;
  - 0228 (uso dos espaços comuns);
  - 0221 (mesmo padrão: regravação pelo cadastro, tudo ou nada, prévia e auditoria).

## Contexto

Para uma obra, uma dedetização ou o fim de uma reforma, o síndico bloqueia ou libera várias áreas comuns de uma vez.
Hoje é área por área no formulário do cadastro (campo "Reserva"). A reserva já confere o modo da área
(`RegistryService::reservationRules`): área bloqueada ou não reservável recusa reserva nova.

## Decisão

Rota nova: `POST /api/v1/common-areas/booking-mode` com `{condominium_id, area_ids, booking_mode, reason, dry_run?}`. O
service é `CommonAreaBookingMode`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada área é regravada pelo `RegistryService::save` do cadastro `common_areas`, só com o novo modo de
  reserva. Valem as regras, a chave única e a auditoria `update` do formulário; os demais campos ficam como estão.
- **Modos:** os do cadastro (`approval`, `free`, `blocked`, `none`), com os mesmos rótulos.
- **Sem período guardado:** a área não tem campo de período de bloqueio, e criar um pediria migration. Bloquear e
  liberar são duas chamadas; o motivo fica na auditoria.

### Regra pura `problem()`

Recusa a área:

- não encontrada no condomínio;
- inativa (ative no cadastro antes);
- já no modo pedido;
- para `blocked` ou `none`: com reserva aguardando aprovação ou confirmada de hoje em diante. A reserva de área bloqueada
  não pode mais ser editada no formulário; cancele ou remarque antes. A contagem sai na mensagem, sem morador.

Liberar (`approval` ou `free`) não depende das reservas. Nenhuma reserva é tocada.

### Gravação

- **Tudo ou nada:** numa transação com as áreas e as reservas futuras travadas (`FOR UPDATE`), uma recusada desfaz todas.
  O 422 traz `_form` (ou os erros do formulário) e `_area`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `booking_mode` por área (de, para e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 100 áreas por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.common_areas.view` e `erp.common_areas.manage`, as mesmas do cadastro das
  áreas comuns; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, nome e modos das áreas e a contagem de reservas futuras. Não saem morador ou unidade.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\CommonAreaBookingModeTest`:**
  - a regra pura e os modos do cadastro;
  - validação e escopo (404);
  - as recusas antes do formulário (reserva futura, mesmo modo, inativa, de outro condomínio, inexistente), sem
    transação aberta e sem gravar; a reserva passada, cancelada ou excluída não conta;
  - contrato de reuso: só pelo cadastro, sem aviso e sem dado de morador; controller e rota.
- **`Integration\CommonAreaBookingModeTest`** (MariaDB, banco `*_test`):
  - áreas e unidade pelo cadastro;
  - a prévia; o bloqueio de duas áreas com a taxa preservada e as auditorias `booking_mode` e `update`;
  - a reserva nova recusada na área bloqueada; a liberação;
  - nenhum aviso na outbox nem notificação além da interna da auditoria;
  - a reserva confirmada futura desfazendo o lote, sem o nome do morador; o escopo.
- **Sem tela nova neste patch.** A lista das áreas comuns pode ganhar "Bloquear" e "Liberar" na seleção; quando ganhar,
  vem o artigo da Central de Ajuda.
