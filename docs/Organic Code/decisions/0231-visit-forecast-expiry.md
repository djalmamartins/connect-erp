<!-- markdownlint-disable MD024 -->
# 0231 — Baixa em lote das visitas previstas vencidas

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0042 (portaria: AP-132 previsão de visita e AP-133 registro de acessos), só como fonte e cadastro reusado;
  - 0230 (movimento da portaria: conta as visitas em aberto vencidas);
  - 0221 (mesmo padrão: baixa das reservas pendentes pelo cadastro, tudo ou nada, prévia e auditoria).

## Contexto

A previsão de visita nasce "Prevista" e fica na lista da portaria até alguém marcar "Realizada" ou "Cancelada". Quando a
data (ou o fim) passa e ninguém marca nada, ela fica em aberto para sempre: polui a lista da portaria e a contagem de
vencidas do movimento da portaria (0230). Hoje cada uma é cancelada à mão no formulário. Busquei `erp_visit_forecasts`
em `source/`: só o cadastro, o relatório 0230 e os dados de exemplo usam a tabela; nenhuma ação em lote existe.

## Decisão

Rota nova: `POST /api/v1/visit-forecasts/expire` com `{condominium_id, before?, forecast_ids?, reason, dry_run?}`. O
service é `VisitForecastExpiry`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada visita é regravada pelo `RegistryService::save` do cadastro `visit_forecasts`, só com a situação
  "Cancelada". Valem as regras e a auditoria `update` do formulário; os demais campos ficam como estão.
- **Mesmo desenho da 0221:** ids opcionais, data de corte, regra pura, tudo ou nada, prévia e auditoria `expire`.
- **Nenhum aviso:** o cadastro não envia aviso; nada vai ao morador nem ao visitante.

### Quais visitas entram

- **Sem `forecast_ids`:** as em aberto do condomínio cujo último dia (o fim, `expected_until`, ou a data) é anterior a
  `before` (padrão hoje; nunca depois de hoje). As que têm acesso registrado na portaria ficam de fora, e a contagem sai
  em `skipped_with_access`.
- **Com `forecast_ids`:** só as informadas. A regra pura `problem()` recusa a visita:
  - não encontrada no condomínio;
  - que não está em aberto (realizada ou cancelada);
  - cuja data ainda não passou;
  - com acesso registrado na portaria (ligado a ela no registro de acessos, não cancelado nem excluído). Essa visita
    aconteceu e deve ser marcada como realizada no cadastro, não cancelada.

### Gravação

- **Tudo ou nada:** numa transação com as visitas e os acessos ligados travados (`FOR UPDATE`), uma recusada desfaz
  todas. O 422 traz `_form` (ou os erros do formulário) e `_forecast`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `expire` por visita (data, fim e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 visitas por vez.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.visit_forecasts.view` e `erp.visit_forecasts.manage`, as mesmas do cadastro;
  sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id e datas das visitas. Não saem visitante, documento, unidade, autorizador ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VisitForecastExpiryTest`:**
  - a regra pura, inclusive o último dia pelo fim e o acesso registrado;
  - validação e escopo (404);
  - as recusas antes do formulário (realizada, ainda válida, de outro condomínio, inexistente, com acesso), sem
    transação aberta e sem gravar; acesso cancelado ou excluído não conta;
  - contrato de reuso: só pelo cadastro, sem aviso e sem dado do visitante; controller e rota.
- **`Integration\VisitForecastExpiryTest`** (MariaDB, banco `*_test`):
  - unidade e visitas pelo cadastro;
  - a prévia com a visita que teve acesso de fora;
  - a baixa das vencidas em aberto (com e sem fim), com a ainda válida, a realizada e a que teve acesso intactas;
  - as auditorias `expire` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem o nome do visitante;
  - os ids com uma ainda válida desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista da previsão de visita pode ganhar "Dar baixa nas vencidas"; quando ganhar, vem o
  artigo da Central de Ajuda.
