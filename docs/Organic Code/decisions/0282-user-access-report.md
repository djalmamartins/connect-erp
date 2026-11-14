<!-- markdownlint-disable MD024 -->
# 0282 — Acessos dos usuários ao ERP

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0006 (controle de acesso: papéis, permissões e exceções, prefixo `erp.` nega sem registro);
  - 0168 (Usuários do Sistema, AP-149, e Permissões de Usuários, AP-150).

## Contexto

Usuários do Sistema (AP-149) lista, um a um, quem tem acesso ao condomínio, com o papel. Permissões de Usuários (AP-150)
mostra as exceções de uma pessoa por vez.

A administradora não vê, de uma vez:

- quantos acessos há por papel;
- quantos estão sem papel ou, pela regra, nem entram no ERP;
- quantos têm exceção de permissão, e de que tipo.

Busquei `user_condominium_access`, `access_user_roles` e `access_user_overrides` em `source/` e no README das ADRs.
Nenhum relatório resume os acessos.

O cadastro de usuários não guarda o último login: `users` não tem a coluna, e o login não grava data. Não há, portanto,
"sem login há mais de 90 dias" neste relatório, e não criei coluna nem migration para isso.

## Decisão

Rota nova: `GET /api/v1/reports/user-access?condominium_id=`, logo depois de `POST /notifications/cancel-stale`. O
service é `UserAccessReport`, em `Source\Services\Erp`.

### Reuso

- **Controle de acesso:** as tabelas que já existem (`access_roles`, `access_user_roles`, `access_role_permissions`,
  `access_user_overrides` e `user_condominium_access`).
- **Regra fail-closed:** `ErpAccess::effective`, aplicada a `erp.access`. Sem papel não acessa; `deny` vence; `allow`
  concede; sem exceção vale o papel.
- **Efeitos das exceções:** `ErpAccess::EFFECTS`.

### Regra pura `summarize()`

- **`rows`:** uma linha por papel cadastrado, do maior nível para o menor, com `users`. Cada usuário conta no papel de
  maior nível (regra pura `topRole()`; empate, o menor slug). `totals`: `users` é a soma das linhas mais `without_role`.
- **`erp_access`:** com e sem acesso ao ERP pela regra, somando `users`.
- **`overrides`:** usuários com alguma exceção e, por efeito de `EFFECTS`, exceções e usuários.
- **`by_status`:** usuários por situação da conta, somando `users`.
- **Ids:** `without_role_ids`, `without_erp_access_ids` e `with_overrides_ids`, até 200 cada, com `truncated`.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.users.view`, as mesmas de Usuários do Sistema. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** papéis, efeitos, situações, contagens e ids. Não saem nome, e-mail, telefone, documento, senha
  nem dado de 2FA.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\UserAccessReportTest`:**
  - a regra pura do papel de maior nível;
  - papéis, sem papel, acesso ao ERP pela regra (`allow` concede, `deny` vence), exceções por efeito e situação da conta;
  - totais iguais à soma das linhas, com o usuário de outro condomínio de fora;
  - os ids e o corte em 200;
  - nenhum nome, e-mail nem documento;
  - efeitos vindos de `ErpAccess::EFFECTS`, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\UserAccessReportTest`** (MariaDB, banco `*_test`):
  - papéis, permissão e exceções de verdade;
  - somente leitura, sem nome, e-mail nem documento;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
