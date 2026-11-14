<!-- markdownlint-disable MD024 -->
# 0264 — Histórico das assembleias do condomínio

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0088 (assembleias: convocação, presença, votação e ata);
  - 0211 (conferência antes da assembleia).

## Contexto

As assembleias (AP-018/088/017, ADR 0088) têm o fluxo de cada uma: convocar, abrir, presença, votação e ata. A
conferência antes da assembleia (ADR 0211) avisa o que vai dar errado. Cada assembleia mostra a sua presença e a sua
apuração.

Ninguém responde, porém, como foram as assembleias de um período:

- quantas ordinárias e extraordinárias houve;
- quantas foram encerradas, canceladas ou ainda estão em andamento;
- quantas unidades compareceram em média;
- quantas compareceram por procuração;
- quantas ficaram sem direito a voto por estarem em atraso;
- quantos itens foram aprovados ou rejeitados.

O síndico usa isso na prestação de contas e para planejar a próxima convocação, por exemplo trocando para assembleia
virtual quando a presença é baixa.

Busquei `erp_assemblies` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o fluxo de cada assembleia
(`Assemblies`) e a conferência (0211). Nenhum relatório soma as assembleias de um período.

## Decisão

Rota nova: `GET /api/v1/reports/assembly-history?condominium_id=&from=&to=`. O período padrão vai de dois anos antes a um
ano depois de hoje, até 3660 dias, pela data da primeira convocação. O service é `AssemblyHistory`, em
`Source\Services\Erp`.

### Reuso

- **Tipos:** `Assemblies::KINDS`.
- **Situações:** as do fluxo da assembleia.
- **Resultado:** o que a apuração gravou no item (`Assemblies::decide`, `result_json.approved`). Nada é recalculado.

### Linhas

A regra pura `summarize()` monta uma linha por tipo, sempre os dois, com:

- `assemblies`: todas as do período; `closed`, `cancelled` e `pending` (rascunho, convocada ou aberta);
- `attended_units`: unidades presentes, somadas nas encerradas; cada unidade conta uma vez por assembleia;
- `avg_attended_units`: a média por assembleia encerrada, ou null sem encerrada;
- `proxies` e `ineligible`: presenças por procuração e sem direito a voto (inadimplente na abertura), nas encerradas;
- `items_decided`, `items_approved` e `items_rejected`: itens apurados, aprovados e não aprovados, das não canceladas.
  Item apurado sem resultado conta como não aprovado.

`totals` soma as contagens, e `avg_attended_units` é recalculada sobre o total. `last_closed_on` traz a data da última
assembleia encerrada no período.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.assemblies.view`, as mesmas das assembleias. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida (condomínio ou período), 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** os tipos, as contagens, as médias e a data. Não saem título, local, pauta, ata, presente, unidade ou
  voto.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\AssemblyHistoryTest`:**
  - o resumo por tipo: encerradas, canceladas e em andamento, presença só das encerradas, procurações e sem direito a voto;
  - itens da cancelada e pendentes que não contam, e o sem resultado como não aprovado;
  - as médias e o total igual à soma das linhas;
  - a consulta sem outro condomínio, sem as de fora do período e sem dado da assembleia nem dos presentes; o período
    padrão;
  - validação do período, escopo (404) e contrato; controller e rota.
- **`Integration\AssemblyHistoryTest`** (MariaDB, banco `*_test`):
  - assembleias, presenças e itens gravados;
  - os totais, as médias por tipo e a última encerrada;
  - somente leitura e sem dado da assembleia; o escopo.
- **Sem tela nova neste patch.** A tela das assembleias pode ganhar o quadro do histórico; quando ganhar, vem o artigo da
  Central de Ajuda.
