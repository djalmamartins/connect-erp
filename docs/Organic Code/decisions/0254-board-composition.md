<!-- markdownlint-disable MD024 -->
# 0254 — Composição do síndico e do conselho

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - cadastro "Síndico e conselho" (AP-014), só como cadastro reusado;
  - 0151 (e-mail e telefone obrigatórios no conselho);
  - 0243 (encerramento em lote dos mandatos vencidos).

## Contexto

O cadastro "Síndico e conselho" (AP-014) guarda o cargo e o mandato de cada membro. O calendário de obrigações lista os
fins de mandato, e o encerramento em lote (ADR 0243) inativa os vencidos.

Ninguém responde, porém, como está a composição numa data:

- se há síndico com o mandato valendo, e até quando;
- quais cargos estão vagos;
- quantos mandatos terminam em 30 ou 90 dias;
- quantos seguem ativos no cadastro já vencidos;
- quantos foram eleitos e ainda não começaram;
- quantos estão sem e-mail ou telefone.

A administradora precisa disso para preparar a assembleia de eleição.

Busquei `erp_board_members` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o cadastro, o
calendário de obrigações (os fins de mandato, um por um), o encerramento em lote e o guia do condomínio novo. Nenhum
relatório mostra a composição por cargo.

## Decisão

Rota nova: `GET /api/v1/reports/board-composition?condominium_id=&on=`. O padrão de `on` é hoje. O service é
`BoardComposition`, em `Source\Services\Erp`.

### Reuso

- **Cargos:** os do cadastro (`RegistryService::definition('board')`).
- **Regra das listas:** sem excluídos.

### Linhas

A regra pura `summarize()` monta uma linha por cargo, sempre todos, com:

- `in_force`: ativos com o mandato valendo em `on`, com o início e o fim incluídos;
- `ending_0_30` e `ending_31_90`: dos em vigor, os que terminam em até 30 dias ou de 31 a 90 dias;
- `expired`: ativos com o fim do mandato antes de `on`;
- `not_started`: ativos com o início depois de `on`;
- `inactive`: inativos no cadastro;
- `incomplete`: ativos sem e-mail ou sem telefone.

Cargo desconhecido conta como "Suplente". `totals` soma as linhas. Fora das linhas:

- `sindico_in_force`: se há síndico em vigor;
- `sindico_term_ends_on`: o fim mais distante entre os síndicos em vigor, ou null sem síndico em vigor;
- `vacant_roles`: os cargos sem ninguém em vigor.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.board.view`, as mesmas do cadastro. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida (condomínio ou data), 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só os cargos, as contagens e a data do fim do mandato do síndico. Não saem nome, documento,
  contato, unidade ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\BoardCompositionTest`:**
  - o resumo por cargo, com o último dia do mandato valendo, o vencido, o eleito a começar, o inativo, o incompleto e o
    desconhecido como Suplente;
  - o síndico em vigor, os cargos vagos e o total igual à soma das linhas;
  - a consulta sem outro condomínio, sem excluídos e sem dado pessoal, e a data padrão;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\BoardCompositionTest`** (MariaDB, banco `*_test`):
  - membros gravados pelo cadastro;
  - os totais, o síndico em vigor e os cargos vagos;
  - somente leitura e sem dado pessoal; o escopo.
- **Sem tela nova neste patch.** A tela do síndico e conselho pode ganhar o quadro da composição; quando ganhar, vem o
  artigo da Central de Ajuda.
