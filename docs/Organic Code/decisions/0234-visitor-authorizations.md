<!-- markdownlint-disable MD024 -->
# 0234 — Situação das autorizações de visitantes

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-012 (cadastro de visitantes), só como fonte dos dados;
  - 0230 (movimento da portaria) e 0232 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

O cadastro de visitantes (AP-012) guarda os visitantes frequentes e prestadores autorizados por unidade, com acesso
permanente ou "autorizado até" uma data, e a lista mostra visitante por visitante. Ninguém vê, de uma vez:

- quantas autorizações seguem "Liberado" com a data já vencida (a portaria pode deixar entrar quem não está mais
  autorizado);
- quantas vencem logo;
- quantas ficaram sem data nem acesso permanente (cadastro antigo, de antes da regra do formulário).

Busquei `erp_visitors` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro usa a tabela; nenhum
relatório resume as autorizações.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/visitor-authorizations?condominium_id=&on=&within_days=`. O service é
`VisitorAuthorizations`, em `Source\Services\Erp`.

### Reuso

- **Situações do cadastro:** `RegistryService::definition('visitors')` (Liberado e Bloqueado).
- **Regra do formulário:** sem acesso permanente, a data "autorizado até" é obrigatória; quem não tem as duas aparece em
  `no_date`.
- **Regra das listas:** sem os excluídos.

### Regra pura `classify()` e `summarize()`

Na data de referência `on` (padrão hoje), com a janela de `within_days` dias (padrão 30, de 1 a 365):

| Situação | Quando |
|---|---|
| `permanent` | liberado com acesso permanente |
| `valid` | liberado com a data depois da janela |
| `expiring` | liberado com a data de `on` até o fim da janela |
| `expired_active` | liberado com a data antes de `on` (pede atenção) |
| `no_date` | liberado sem acesso permanente e sem data (pede atenção) |
| `blocked` | bloqueado no cadastro |

- Uma linha por situação, sempre as seis.
- `totals.visitors` é a soma das linhas; `totals.active` soma as cinco liberadas; `totals.attention` = `expired_active`
  + `no_date`.
- `expired_by_age` separa os vencidos e ainda liberados pelos dias desde o vencimento (até 30, 31 a 90, mais de 90),
  somando `expired_active`.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.visitors.view`, a mesma do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só as situações e as contagens. A consulta nem lê nome, documento, telefone, unidade ou
  observação.
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\VisitorAuthorizationsTest`:**
  - a regra pura: as seis situações, o permanente com data, as bordas da janela, os vencidos por idade e os totais;
  - a montagem no SQLite: sem os excluídos e os de outro condomínio, e sem dado pessoal;
  - validação (data, janela), escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\VisitorAuthorizationsTest`** (MariaDB, banco `*_test`): visitantes gravados pelo cadastro, as
  situações, os totais, a idade do vencido, nada na auditoria, sem dado pessoal e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Autorizações de visitantes"; quando ganhar, vem o
  artigo da Central de Ajuda. Uma baixa em lote das autorizações vencidas pode vir depois, no mesmo desenho da 0231.
