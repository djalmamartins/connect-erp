<!-- markdownlint-disable MD024 -->
# 0293 — Alíquotas próprias que só repetem o padrão inativadas em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0262 (cobertura das alíquotas de retenção: a origem de cada imposto e `overrides_default`);
  - 0085 (configurações do ERP: o cadastro de impostos, AP-164; sem condomínio, é o padrão da Connect).

## Contexto

O coordenador pediu uma ação em lote segura. Pediu também que, se fosse ligada ao relatório da 0292, não cancelasse
documentos já entregues (o código de verificação é público). Não há ação segura em lote nos documentos da unidade, então
escolhi outro relatório que ainda não tinha a sua: a cobertura das alíquotas (ADR 0262).

Pela regra de origem da cobertura, quando o condomínio tem alíquota própria ativa de um imposto, valem só as próprias; o
padrão é ignorado. Quando as próprias são exatamente as do padrão, a substituição não muda nada hoje, mas congela o
condomínio: se a Connect atualizar o padrão, ele segue com a cópia antiga.

## Decisão

Rota nova: `POST /api/v1/taxes/deactivate-redundant {condominium_id, kinds?, reason, dry_run?}`, logo depois de
`GET /reports/unit-documents`. O service é `TaxOverrideCleanup`, em `Source\Services\Erp`.

### Reuso

- **Cadastro:** `RegistryService::definition('taxes')` dá a tabela, a permissão (`erp.settings`, exigida em `.view` e
  `.manage`) e os impostos do campo `kind`.
- **Regra de origem:** a do `TaxCoverage` (ADR 0262). O teste unit confere que ela segue igual e que a cobertura de antes
  e de depois tem a mesma alíquota em vigor.
- **Inativar:** o status `inactive` do cadastro, com `updated_by`. Nada é apagado.
- **Lote:** o mesmo desenho das ADRs 0283, 0285, 0287, 0289 e 0291.

### Regras

- **O lote é por imposto.** Entram todas as alíquotas próprias ativas do imposto redundante, porque inativar só parte
  delas mudaria a alíquota em vigor.
- **`redundant()`** (regra pura): as próprias ativas e as padrão ativas formam o mesmo conjunto de taxa (duas casas) e
  aplicação, contando as repetidas.
- **Sem `kinds`:** entram todos os impostos redundantes do condomínio. **Com `kinds`:** só os informados. A regra pura
  `problem()` recusa:
  - imposto desconhecido no cadastro;
  - sem alíquota própria ativa;
  - sem padrão ativo (a própria é a única que vale);
  - próprias diferentes do padrão.
- **Tudo ou nada:** numa transação, com as alíquotas do condomínio e do padrão travadas (`FOR UPDATE`). Uma recusa desfaz
  todas (422, `_form` e `_kind`). O `UPDATE` só vale para alíquota do próprio condomínio e ainda ativa; se outro pedido
  mudou antes, é 409.
- **O padrão nunca é tocado**, nem as alíquotas de outro condomínio.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Auditoria:** um registro `update` por alíquota (status, motivo, imposto e tamanho do lote), só quando grava.
- **Limites:** até 500 alíquotas por vez; motivo obrigatório (5 a 255 caracteres).

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.settings.view` e `erp.settings.manage`, as da tela de impostos. Sem uma delas,
  403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** impostos, ids, taxas e aplicação. Não saem nome de alíquota nem observação.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TaxOverrideCleanupTest`:**
  - a regra pura (ordem, repetidas, aplicação e taxa diferentes);
  - a validação e o escopo;
  - a prévia e a inativação só das próprias do imposto redundante, sem tocar o padrão, os outros impostos nem o outro
    condomínio, e sem apagar;
  - a cobertura da 0262 antes e depois, com a mesma alíquota em vigor e a origem voltando ao padrão;
  - as recusas, uma por caso, desfazendo tudo;
  - nenhum nome nem observação;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\TaxOverrideCleanupTest`** (MariaDB, banco `*_test`):
  - alíquotas de verdade, próprias e padrão;
  - a prévia sem gravar nem auditar;
  - o imposto diferente do padrão recusando o lote todo;
  - a gravação com auditoria, com o padrão intocado;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
