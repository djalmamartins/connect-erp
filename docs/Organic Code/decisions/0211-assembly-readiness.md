<!-- markdownlint-disable MD024 -->
# 0211 — Conferência antes da assembleia

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0088 (assembleia e votação), 0094 (presença), 0107 (apuração), 0039 (inadimplência), 0181
  (conferência antes de fechar o mês, o mesmo formato)

## Contexto

A assembleia (ADR 0088) tem regras que só aparecem tarde, na hora:

- o edital exige 8 dias (o convene recusa);
- a unidade sem fração ideal tem o voto recusado no item por fração;
- a unidade inadimplente fica presente, mas não vota;
- a apuração usa o total do condomínio.

Na prática, a administradora descobre na noite da assembleia que o item de dois terços não tem como passar, porque 40%
da fração está inadimplente, ou que três unidades não têm fração cadastrada. Aí só resta remarcar.

## Decisão

Rota nova, somente leitura: `GET /api/v1/assemblies/{id}/readiness`. O service é `AssemblyReadiness`, no mesmo
formato da conferência antes de fechar o mês (ADR 0181).

### Reuso, sem cálculo paralelo

O service não tem consulta própria; usa as regras e os dados que a assembleia já usa:

- **`Assemblies::find`:** escopo e itens.
- **`Assemblies::MIN_NOTICE_DAYS`:** o prazo do edital do `convene`.
- **`Assemblies::totalWeight`:** o total que a apuração usa.
- **`Assemblies::overdueCents`:** a regra do `checkIn` que tira o voto do inadimplente (ADR 0039).
- **`Assemblies::weight` e `Assemblies::decide`:** o peso e a apuração.
- **`UnitBatch::measures`:** unidades ativas, soma das frações e frações faltando, com a tolerância
  `params.fraction_tolerance`.

`totalWeight` e `overdueCents` passaram de `private` para `public` (só a visibilidade, sem mudar o comportamento).

### Regra pura `evaluate()`

**Erros** (a assembleia sai errada assim):

- **`notice_deadline`:** rascunho com a primeira convocação a menos de 8 dias.
- **`no_units`:** o condomínio não tem unidade ativa.
- **`fraction_missing`:** item por fração e unidade ativa sem fração ideal.
- **`quorum_unreachable`:** nem com todas as unidades aptas votando sim o item é aprovado.

**Avisos:**

- **`fraction_sum`:** as frações estão completas, mas a soma não dá 100%.
- **`debtors`:** unidades que hoje não votariam.

**Informação:**

- **`no_second_call`:** a assembleia não tem segunda convocação.

**Por item:** total, aptas, o percentual e se o quórum é alcançável.

### Acesso e dados

- **Situações:** só para assembleia em rascunho, convocada ou aberta. Encerrada ou cancelada dá 422.
- **Escopo:** pela assembleia; fora do escopo, 404.
- **Permissão:** exige `erp.access` e `erp.assemblies.view`, as mesmas da assembleia (fail-closed).
- **Dados expostos:** contagens, percentuais e a pauta. Nenhuma unidade, morador ou valor (o valor da dívida por unidade
  segue restrito ao relatório de presença com `erp.agreements.view`, ADR 0094).
- Não grava, não audita e não envia nada.

## Consequências

- **Testes.**
  - `Unit\AssemblyReadinessTest`:
    - a regra pura: pronta, prazo do edital (menos de 8 dias, exatamente 8, já convocada), fração faltando (e item por
      unidade sem depender dela), soma das frações, cada quórum no limite, inadimplentes, condomínio sem unidade;
    - o contrato de reuso: chama as regras da assembleia, não tem consulta própria, e os dois métodos são públicos.
  - `Integration\AssemblyReadinessTest` (MariaDB, banco `*_test`):
    - assembleia criada pelo `Assemblies::create` e unidades com fração;
    - a 101 inadimplente deixa os dois terços fora de alcance, com o edital em menos de 8 dias;
    - paga, tudo alcançável;
    - fração apagada dá `fraction_missing`;
    - nada gravado, auditado ou enviado;
    - escopo e assembleia cancelada.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Sem tela nova neste patch.** A tela da assembleia pode mostrar a conferência antes do "Convocar" e do "Abrir";
  quando mostrar, vem o artigo da Central de Ajuda.
