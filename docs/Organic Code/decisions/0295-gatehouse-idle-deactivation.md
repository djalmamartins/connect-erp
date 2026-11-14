<!-- markdownlint-disable MD024 -->
# 0295 — Portarias paradas inativadas em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0294 (situação das portarias: `idle_ids`);
  - 0042 (portaria: portarias, registro de acessos e entregas, AP-131 a AP-135);
  - 0166 (Registro de Entregas: a encomenda aguardando retirada).

## Contexto

A situação das portarias (ADR 0294) aponta as portarias ativas sem acesso no período. A portaria desativada no prédio
(troca para remota, fusão de guaritas) segue ativa no cadastro e aparece nos formulários de acesso e de entrega. Inativar
era uma por uma, pelo cadastro.

## Decisão

Rota nova: `POST /api/v1/gatehouses/deactivate-idle {condominium_id, days?, gatehouse_ids?, reason, dry_run?}`, logo
depois de `GET /reports/gatehouses`. O service é `GatehouseDeactivation`, em `Source\Services\Erp`.

### Reuso

- **Cadastros do registro** (`RegistryService::definition`):
  - `gatehouses`: a tabela, a permissão (`erp.gatehouses`, exigida em `.view` e `.manage`) e os tipos;
  - `access_logs` e `deliveries`: as tabelas e as permissões de ver, que entram na conferência.
- **Regras das listas:** sem os excluídos; acesso cancelado não conta; encomenda aguardando retirada é `status = active`.
- **Janela:** a da situação das portarias (`GatehouseOverview`), os últimos `days` dias até hoje, no dia do banco.
- **Inativar:** o status `inactive` do cadastro, com `updated_by`.
- **Lote:** o mesmo desenho das ADRs 0283 a 0293.

### Regras

- **Sem `gatehouse_ids`:** entram as portarias ativas do condomínio sem acesso nos últimos `days` dias (padrão 90, de 30
  a 3650) e sem encomenda aguardando retirada. As paradas com encomenda aguardando ficam de fora e são contadas em
  `skipped_pending`.
- **Com `gatehouse_ids`:** só as informadas. A regra pura `problem()` recusa:
  - portaria não encontrada no condomínio (outro condomínio ou excluída);
  - já inativa;
  - com encomenda aguardando retirada;
  - com acesso no período (o primeiro dia da janela conta).
- **Tudo ou nada:** numa transação, com as portarias travadas (`FOR UPDATE`). A trava da portaria segura também o
  registro de um acesso ou de uma encomenda nela até o fim, porque a chave estrangeira confere a portaria. Uma recusa
  desfaz todas (422, `_form` e `_gatehouse`). O `UPDATE` só vale com `status = 'active'`; se outro pedido mudou antes, é
  409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Auditoria:** um registro `update` por portaria (status, dias, último acesso, motivo e tamanho do lote), só quando
  grava.
- **Nada é apagado.** Os acessos e as encomendas já registrados continuam apontando a portaria.
- **Limites:** até 500 por vez; motivo obrigatório (5 a 255 caracteres).

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.gatehouses.view`, `erp.gatehouses.manage`, `erp.access_logs.view` e
  `erp.deliveries.view`. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, tipo e a data do último acesso. Não saem nome de portaria, responsável, telefone nem pessoa.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\GatehouseDeactivationTest`:**
  - a regra pura, com o limite da janela;
  - a validação e o escopo;
  - a prévia e a inativação só das paradas e sem encomenda aguardando, com as que têm encomenda contadas à parte;
  - acesso cancelado e excluído sem contar;
  - nada apagado;
  - as recusas, uma por caso, desfazendo tudo;
  - a situação da 0294 depois;
  - nenhum nome, responsável, telefone nem pessoa;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\GatehouseDeactivationTest`** (MariaDB, banco `*_test`):
  - portarias, acessos e encomendas de verdade, com o dia do banco;
  - a prévia sem gravar nem auditar;
  - a portaria com acesso recente recusando o lote todo;
  - a gravação com auditoria;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
