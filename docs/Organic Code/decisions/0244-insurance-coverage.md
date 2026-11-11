<!-- markdownlint-disable MD024 -->
# 0244 — Cobertura de seguros do condomínio numa data

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0051 (cadastros operacionais: AP-118 seguros), só como cadastro reusado;
  - painel operacional e calendário de obrigações (contam e listam os fins de vigência);
  - 0242 (mesmo desenho de relatório por faixa de prazo).

## Contexto

O cadastro de seguros (AP-118) guarda a vigência, o prêmio, a cobertura e a apólice em PDF. Hoje:

- o painel operacional conta os seguros vencidos e os a renovar em 30 dias;
- o calendário de obrigações lista os fins de vigência.

Ninguém responde, porém, se o condomínio está coberto na data e até quando a cobertura segue sem interrupção, somando a
renovação já cadastrada. Também não há visão de quanto se paga de prêmio e quanto está coberto por faixa de vigência, nem
de quais apólices estão sem o PDF. O seguro da edificação é obrigatório (Código Civil, art. 1.346), e um dia descoberto
entre duas apólices é o risco que a administradora precisa ver.

Busquei `erp_insurances` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro, o painel operacional e o
calendário de obrigações leem a tabela; nenhum relatório trata da cobertura.

## Decisão

Rota nova: `GET /api/v1/reports/insurance-coverage?condominium_id=&on=`. O service é `InsuranceCoverage`, em
`Source\Services\Erp`.

### Reuso

- **Regra das listas do cadastro:** só apólices vigentes no cadastro, sem excluídas.
- **Regra de anexo do cadastro:** o PDF vale quando o documento existe no Disco Virtual do mesmo condomínio e não foi
  removido.

### Faixas e cobertura

A regra pura `bucket()` põe cada apólice vigente no cadastro numa faixa:

- `expired`: o fim da vigência é anterior à data `on` (padrão hoje);
- `renew_0_30`, `renew_31_90`, `in_force_over_90`: em vigor, com o fim em tantos dias (0 = termina na data);
- `not_started`: a vigência começa depois da data (a renovação já cadastrada).

A regra pura `summarize()` monta uma linha por faixa, sempre as cinco, com `policies`, `premium_cents`, `coverage_cents` e
`without_pdf`. `totals` é a soma das linhas. Fora das linhas:

- `covered`: há apólice em vigor na data;
- `covered_until`: o último dia coberto sem interrupção a partir da data (regra pura `coveredUntil()`), emendando as
  apólices que começam até o dia seguinte ao fim da anterior; `null` = descoberto na data;
- `cancelled`: as apólices canceladas.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.insurances.view`, as mesmas do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as faixas, as contagens, as somas e as datas da cobertura. Não saem seguradora, apólice,
  corretora ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\InsuranceCoverageTest`:**
  - a regra pura das faixas, com o fim no próprio dia, 30, 31, 90 e 91 dias, a vencida e a futura;
  - a cobertura emendada, descoberta, com um dia de intervalo e fora de ordem;
  - o resumo: as cinco linhas, as somas, o total igual à soma das linhas;
  - a consulta, sem canceladas, excluídas ou outro condomínio, com o PDF removido e o de outro condomínio contados como
    sem PDF, e sem dado da apólice;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\InsuranceCoverageTest`** (MariaDB, banco `*_test`):
  - apólices gravadas pelo cadastro: vencida, em vigor, renovação emendada e cancelada;
  - as faixas, o prêmio convertido pelo cadastro, `covered_until` na renovação e o descoberto mais à frente;
  - somente leitura e sem dado da apólice; o escopo.
- **Sem tela nova neste patch.** A tela de seguros pode ganhar o quadro da cobertura; quando ganhar, vem o artigo da
  Central de Ajuda.
