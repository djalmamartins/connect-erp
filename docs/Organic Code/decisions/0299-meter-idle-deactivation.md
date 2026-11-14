<!-- markdownlint-disable MD024 -->
# 0299 — Relógios parados inativados em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0220 (conferência das leituras do mês: o relógio ativo sem leitura aparece como missing);
  - 0258 (cobertura das tarifas: conta os relógios ativos);
  - 0283 a 0297 (o desenho das ações em lote).

## Contexto

A conferência das leituras do mês (ADR 0220) aponta como `missing` todo relógio ativo sem leitura na competência. O relógio
retirado (unidade que passou a ser medida pela concessionária, troca de medidor com número novo) segue ativo no cadastro. Ele
aparece todo mês como leitura em falta e na escolha do relógio ao lançar a leitura. Inativar era um por um, pelo cadastro.

**Por que não as contas bancárias (ADR 0298).** A ação sugerida pelo coordenador era inativar contas bancárias paradas.
Busquei as chaves estrangeiras para `erp_bank_accounts`: credenciais, cobranças (boletos), eventos e retornos do banco,
entregas de webhook, extratos, cópias de cheque, importações, lançamentos patrimoniais, recorrentes, lançamentos e
transferências. Uma regra segura teria de conferir o saldo zerado e a ausência de boleto ou integração ativa. Isso exige ler
as tabelas da integração bancária (AP-031/AP-037), fora do escopo desta frente. Uma conta inativada com boleto ainda por
pagar poderia afetar o retorno do banco. Fica para uma decisão própria, com a integração bancária na mesa.

**Por que os relógios.** Busquei `erp_meters` em `source/`: só as leituras (`erp_meter_readings`) apontam o relógio. A
leitura (`MeterReadings::meter`) só aceita relógio ativo, e a cobrança (`MeterReadings::bill`) cobra as leituras da
competência sem conferir a situação do relógio.

## Decisão

Rota nova: `POST /api/v1/meters/deactivate-idle {condominium_id, months?, meter_ids?, reason, dry_run?}`, logo depois de
`GET /reports/bank-accounts`. O service é `MeterDeactivation`, em `Source\Services\Erp`.

### Reuso

- **Cadastro do registro** (`RegistryService::definition('meters')`): a tabela, a permissão e os tipos.
- **Leituras:** uma por relógio e competência; cobrada quando tem `entry_id`, como em `MeterReadings::bill`.
- **Período:** `DiscountOverview::months()` pela competência, no mês do banco (`SELECT NOW()`).
- **Status:** o `inactive` do cadastro, com `updated_by`, que a leitura e a conferência já respeitam.

### Regra

- **Sem `meter_ids`:** entram os relógios ativos do condomínio sem leitura nos últimos `months` meses (padrão 12, de 3 a
  120) e cadastrados e instalados antes do período. Os parados com leitura ainda não cobrada (valor acima de zero e sem
  `entry_id`) ficam de fora, em `skipped_unbilled`.
- **Com `meter_ids`:** só os informados. A regra pura `problem()` recusa:
  - relógio não encontrado no condomínio (excluído ou de outro condomínio);
  - já inativo;
  - com leitura não cobrada (a cobrança não confere a situação do relógio);
  - com leitura no período;
  - cadastrado ou instalado no período.
- **Tudo ou nada:** uma transação com os relógios travados (`FOR UPDATE`). A leitura trava o relógio antes de gravar
  (`MeterReadings::record`), e a chave estrangeira da leitura confere o relógio. Um recusado desfaz todos (422, `_form` e
  `_meter`). O `UPDATE` exige `status = 'active'`; se não mudar uma linha, 409.
- **`dry_run`:** passa por tudo e desfaz, sem auditoria.
- **Auditoria:** um `update` por relógio (status, meses, última competência, motivo e tamanho do lote), só quando grava.
- **Limites:** até 500 por vez; motivo de 5 a 255.

### Acesso e dados

- **Permissão:** `erp.access`, `erp.meters.view`, `erp.meters.manage` e `erp.meter_readings.view`. Sem uma delas, 403
  (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404, antes de consultar. Entrada inválida, 422.
- **Dados que saem:** ids, tipo e a última competência, mais `skipped_unbilled`, `by_kind`, `count` e `changed`. Não saem
  número de relógio, unidade, leitura, consumo nem valor.
- **Nada é apagado.** As leituras e as cobranças já feitas continuam apontando o relógio.
- **Não toca** contas bancárias, credenciais, o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão
  por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\MeterDeactivationTest`:**
  - a regra pura nos limites do período, do cadastro e da instalação;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito;
  - a prévia e a inativação só dos parados, com os de leitura não cobrada contados à parte (a leitura de valor zero não
    espera cobrança);
  - leituras e outro condomínio intactos, sem apagar;
  - o contrato (a leitura só aceita relógio ativo), o controller, a rota e a OpenAPI.
- **`Integration\MeterDeactivationTest`** (MariaDB, banco `*_test`): o mês do banco, a prévia sem auditoria, a recusa do
  relógio com leitura recente, a inativação com um registro por relógio e as leituras intactas.
- **Corrida conhecida:** a leitura confere o relógio ativo antes de travá-lo. Se a inativação terminar entre a conferência e
  a trava, a leitura é gravada num relógio inativo. É o mesmo comportamento da inativação pelo cadastro.
- **Numeração:** esta é a última ADR da faixa 0200–0299. A próxima do Avançar é a 0400 (faixa 0400–0599).
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
