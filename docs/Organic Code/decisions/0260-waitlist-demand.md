<!-- markdownlint-disable MD024 -->
# 0260 — Procura pelas áreas comuns na lista de espera

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0237 (baixa em lote da lista de espera);
  - uso das áreas comuns (`CommonAreaUsage`);
  - lista de espera (AP-115), só como cadastro reusado.

## Contexto

A lista de espera (AP-115) guarda quem quer uma área comum numa data já reservada. O painel operacional chama o próximo
quando a reserva cai, e a baixa em lote (ADR 0237) encerra as inscrições de datas que passaram.

Ninguém responde, porém, quais áreas têm mais procura do que comportam:

- quantas inscrições esperam por área, e em quantas datas;
- quantas unidades diferentes querem a área;
- quantas já foram chamadas;
- quantas foram atendidas ou desistiram.

O síndico usa isso para decidir horário estendido, um segundo turno ou a reforma da churrasqueira disputada.

Busquei `erp_waitlist` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o cadastro, o painel (o
chamado do próximo) e a baixa em lote. O uso das áreas conta as reservas, não a espera.

## Decisão

Rota nova: `GET /api/v1/reports/waitlist-demand?condominium_id=&from=&to=`. O período padrão vai de um ano antes a um ano
depois de hoje, com até 1100 dias. O service é `WaitlistDemand`, em `Source\Services\Erp`.

### Reuso

- **Áreas:** as do cadastro (`erp_common_areas`, sem as excluídas).
- **Situações da lista de espera:** aguardando, atendida e desistiu, sem as inscrições excluídas.

### Linhas

A regra pura `summarize()` monta uma linha por área do condomínio, com as inscrições de data desejada no período. Se
houver inscrições de área excluída, entra também uma linha "Área excluída". Cada linha traz:

- `waiting_upcoming` e `waiting_past`: aguardando com a data de hoje em diante, e antes de hoje (a encerrar pelo ADR
  0237);
- `called`: das aguardando, as já chamadas;
- `served` e `gave_up`: atendidas e desistências;
- `entries`: todas as inscrições;
- `units`: unidades diferentes;
- `dates_upcoming`: datas diferentes com alguém aguardando;
- `next_desired_on`: a próxima data com alguém aguardando.

`totals` soma as contagens das linhas.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.reservations.view`, as mesmas da lista de espera e do uso das áreas. Sem uma
  delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida (condomínio ou período), 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** a área (id, nome e situação), as contagens e as datas. Não saem morador, unidade ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\WaitlistDemandTest`:**
  - o resumo por área, com hoje contado como próxima data, as chamadas, as atendidas, as desistências, as unidades e as
    datas diferentes, a próxima data e a linha da área excluída;
  - o total igual à soma das linhas;
  - a consulta sem outro condomínio, sem excluídas, só o período pedido e sem dado do morador;
  - validação do período, escopo (404) e contrato; controller e rota.
- **`Integration\WaitlistDemandTest`** (MariaDB, banco `*_test`):
  - inscrições gravadas pelo cadastro, uma chamada e uma atendida;
  - as linhas e os totais;
  - somente leitura e sem dado do morador; o escopo.
- **Sem tela nova neste patch.** A tela da lista de espera pode ganhar o quadro da procura; quando ganhar, vem o artigo da
  Central de Ajuda.
