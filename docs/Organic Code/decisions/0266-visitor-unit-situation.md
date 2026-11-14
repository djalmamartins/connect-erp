<!-- markdownlint-disable MD024 -->
# 0266 — Visitantes pela situação da unidade

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0234 (situação das autorizações de visitantes, pela data);
  - 0240 (perfil de ocupação das unidades);
  - 0265 (bloqueio em lote dos visitantes de unidades sem morador ativo);
  - cadastro de visitantes (AP-012), só como cadastro reusado.

## Contexto

O relatório das autorizações (ADR 0234) conta os visitantes pela data "autorizado até". O perfil de ocupação (ADR 0240)
conta as unidades pelos vínculos. Nenhum cruza os dois.

A administradora não vê, de uma vez:

- quantos visitantes seguem "Liberado" em unidade que já não tem morador ativo (a portaria deixa entrar quem foi
  autorizado por quem saiu);
- quantos estão em unidade inativa;
- quantos têm só o texto da unidade, sem vínculo.

O bloqueio em lote (ADR 0265) age sobre o primeiro caso; faltava ver o tamanho antes de agir.

Busquei `erp_visitors` em `source/`, no README das ADRs e nos handoffs 02xx. Usam a tabela o cadastro, o relatório e o
bloqueio das autorizações (0234 e 0235) e o bloqueio em lote (0265). Nenhum relatório agrupa os visitantes pela unidade.

## Decisão

Rota nova: `GET /api/v1/reports/visitor-units?condominium_id=`, logo depois de `POST /visitors/block-unattached` e antes do
laço dos cadastros. O service é `VisitorUnitSituation`, em `Source\Services\Erp`.

### Reuso

- **Situações:** as do cadastro (`RegistryService::definition('visitors')`: Liberado e Bloqueado). Sem os excluídos.
- **Morador ativo:** a regra do bloqueio em lote (0265): vínculo ativo, sem excluído e sem fim de vínculo passado.
- **Ordem da classificação:** a mesma do bloqueio em lote, para que `attention.blockable` seja o que ele pegaria hoje sem
  ids.

### Regra pura `classify()` e `summarize()`

Cada visitante cai num grupo:

- `inhabited`: unidade com morador ativo;
- `empty`: unidade ativa sem morador ativo;
- `inactive_unit`: unidade inativa ou excluída, sem morador ativo;
- `unlinked`: só o texto da unidade.

Uma linha por grupo, sempre as quatro, com:

- `units`: unidades distintas com visitante (0 em `unlinked`);
- `visitors` = `active` + `blocked`;
- `permanent`: liberados com acesso permanente.

`totals` soma as linhas. `attention` separa os liberados de unidade sem morador em `blockable` (com data ou acesso
permanente) e `incomplete` (sem os dois: o formulário não regrava), somando `empty_active`. `concentration` conta as
unidades vinculadas com visitante liberado pela quantidade de liberados (`um`, `de_2_a_5`, `mais_de_5`), somando
`units_with_active`.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.visitors.view` e `erp.residents.view`, porque o morador ativo vem do cadastro de
  moradores. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só os grupos e as contagens. Não saem nome, documento, telefone, rótulo ou id de unidade, ou
  observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VisitorUnitSituationTest`:**
  - a regra pura, com totais iguais à soma das linhas;
  - a atenção (data vencida conta como bloqueável) e a concentração;
  - o morador com fim de vínculo passado, inativo ou excluído contando como unidade vazia;
  - a unidade inativa ou excluída;
  - nenhum dado pessoal;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\VisitorUnitSituationTest`** (MariaDB, banco `*_test`):
  - os grupos com os cadastros;
  - somente leitura e sem dado pessoal;
  - `attention` igual à prévia do bloqueio em lote, e zerada depois dele;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela de visitantes pode ganhar o quadro com o
  botão do bloqueio em lote; quando ganhar, vem o artigo.
