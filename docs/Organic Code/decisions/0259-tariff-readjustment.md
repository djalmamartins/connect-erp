<!-- markdownlint-disable MD024 -->
# 0259 — Reajuste em lote das tarifas de consumo do condomínio

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0258 (cobertura das tarifas);
  - 0230 (reajuste dos lançamentos fixos, mesmo percentual e arredondamento);
  - cadastro de tarifas (AP-043), só como cadastro reusado.

## Contexto

O cadastro de tarifas (AP-043) guarda as faixas progressivas por tipo e vigência. A cobertura das tarifas (ADR 0258)
mostra a vigência em vigor e se já há reajuste cadastrado.

Quando a concessionária reajusta a água ou o gás, a administradora recadastra faixa por faixa, com a nova vigência e o
preço recalculado à mão. Uma faixa esquecida fica com o preço antigo, e a leitura do mês sai errada.

Busquei `erp_tariffs` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o cadastro, as leituras e a
cobertura (somente leitura). Os reajustes em lote existentes são de lançamentos fixos, contratos e salários; nenhum
reajusta tarifas.

## Decisão

Rota nova: `POST /api/v1/tariffs/readjust {condominium_id, kinds?, percent, valid_from, reason, dry_run?}`, antes do
laço dos cadastros (`/tariffs/{id}`). O service é `TariffReadjustment`, em `Source\Services\Erp`.

### Reuso

- **Percentual:** `RecurringReadjustment::basisPoints` e `::readjusted`, de −50% a 100%, com até 2 casas, diferente de
  zero e com o mesmo arredondamento dos outros reajustes.
- **Gravação:** cada faixa nova é criada pelo `RegistryService::save` do cadastro "tariffs", com o mesmo tipo, nome,
  início, fim e observação da faixa vigente, o preço reajustado e a nova vigência. Valem as regras e a auditoria create
  do formulário.
- **Faixas antigas:** não mudam. As leituras anteriores à nova vigência continuam usando o preço antigo, pela regra de
  `MeterReadings::bands`.

### Quais entram

Entra a tarifa própria do condomínio vigente antes de `valid_from`, de cada tipo escolhido (padrão todos).

A regra pura `problem()` recusa o tipo:

- sem tarifa própria vigente antes da data: o condomínio usa a padrão da Connect, que não é reajustada aqui;
- com vigência própria já cadastrada em `valid_from` ou depois.

Sem tipos, esses ficam de fora e vêm listados em `skipped_default` e `skipped_already`. Com tipos, um recusado desfaz
todos (`_form` e `_kind`).

`valid_from` precisa ser hoje ou uma data futura, porque um reajuste com data passada mudaria o preço de leituras já
lançadas.

### Execução

- **Tudo ou nada:** uma transação; uma faixa recusada pelo formulário desfaz todas. `dry_run` passa por tudo e desfaz.
- **Auditoria:** a create do formulário e um registro `readjust` por faixa nova (vigência e preço antes e depois,
  percentual e motivo), só quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.tariffs.view` e `erp.tariffs.manage`, as do cadastro. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou tipo recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** os tipos, as vigências, os limites e os preços das faixas, antes e depois. Não saem nome da faixa ou
  observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TariffReadjustmentTest`:**
  - a regra pura;
  - a validação (percentual, data, data passada, motivo, tipos) e o escopo;
  - as recusas por tipo, sem gravar;
  - os tipos que ficam de fora sem escolher;
  - o contrato de reuso, o controller e a rota.
- **`Integration\TariffReadjustmentTest`** (MariaDB, banco `*_test`):
  - a prévia e a criação pelo cadastro: as faixas antigas ficam, as novas valem a partir de amanhã com 10% a mais e com o
    mesmo limite, nome e observação;
  - o gás pela tarifa padrão fica de fora;
  - as auditorias `readjust` e create;
  - nenhum aviso e nenhum nome de faixa na resposta;
  - um segundo lote não reajusta de novo;
  - tudo ou nada com tipos e o escopo.
- **Sem tela nova neste patch.** A tela de tarifas pode ganhar o botão "Reajustar"; quando ganhar, vem o artigo da Central
  de Ajuda.
