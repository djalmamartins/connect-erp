<!-- markdownlint-disable MD024 -->
# 0283 — Acesso ao condomínio tirado em lote de quem não entra no ERP

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0168 (Usuários do Sistema, AP-149, com o "desativar");
  - 0282 (acessos dos usuários ao ERP, que aponta quem não entra em `without_erp_access_ids`).

## Contexto

Os acessos dos usuários (ADR 0282) mostram quem tem a linha em `user_condominium_access` mas não entra no ERP: sem
papel, papel sem `erp.access` ou bloqueio por exceção. A linha fica esquecida e volta a valer se o papel mudar. Tirar hoje
é um "desativar" por pessoa, na tela de usuários.

Busquei `user_condominium_access`, `revoke` e `deactivate` em `source/`. O `ErpAccess::deactivate` já tira o acesso de
uma pessoa a um condomínio, auditado. O `CondominiumScope::revoke` também apaga a linha, mas sem auditoria nem conferência
de quem pode mexer em quem.

## Decisão

Rota nova: `POST /api/v1/users/revoke-condominium-access`, logo depois de `GET /reports/user-access`. O service é
`UserAccessRevocation`, em `Source\Services\Erp`.

### Reuso

- **Saída do acesso:** pelo `ErpAccess::deactivate`, o mesmo "desativar" da tela e da API de usuários. Ele só apaga a
  linha de `user_condominium_access`, audita a exclusão, limpa o cache de permissões e confere de novo quem pode mexer em
  quem.
- **Regra de entrada no ERP:** `ErpAccess::effective` sobre `erp.access`.
- **Papel que vale:** o de maior nível (`UserAccessReport::topRole`).
- **Quem pode ser alterado:** `ErpAccess::canManage`. Nunca o próprio usuário, nunca papel igual ou acima do de quem
  pede, nunca papel protegido.
- **Intactos:** papéis, exceções, situação da conta, senha e 2FA.

### Lote

- **Sem `user_ids`:** entram os usuários do condomínio que não entram no ERP e que quem pede pode alterar. Os que não
  podem ser alterados ficam de fora, contados em `skipped_unmanageable`.
- **Com `user_ids`:** entram só os informados. A regra pura `problem()` recusa:
  - sem acesso ao condomínio;
  - o próprio usuário;
  - quem entra no ERP pela regra;
  - quem não pode ser alterado.
- **Causa:** a regra pura `cause()` diz por que o usuário não entra (`CAUSES`: sem papel, papel sem acesso ao ERP ou
  bloqueado por exceção).
- **Tudo ou nada:** uma transação com as linhas do condomínio travadas (`FOR UPDATE`). Um recusado desfaz todos (422,
  com `_form` e `_user`).
- **Prévia:** `dry_run` passa por tudo e desfaz, inclusive a auditoria da exclusão, que vai na mesma transação.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Até 500 usuários por vez.
- **Auditoria:** a do `ErpAccess::deactivate` e um registro `revoke_no_erp` por usuário (causa, motivo e tamanho do
  lote), só quando grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.users.view` e `erp.users.manage`, as mesmas do "desativar" de Usuários do
  Sistema. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422. Sem poder sobre o papel, 403. Nada a tirar no meio
  do pedido, 409.
- **Dados que saem:** ids, causa e contagens. `count` é a soma por causa. Não saem nome, e-mail, telefone ou documento.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.**

## Consequências

- **`Unit\UserAccessRevocationTest`:**
  - a regra pura da causa e das recusas;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito e sem nome nem e-mail na mensagem;
  - a prévia, o papel acima de fora e a saída só da linha deste condomínio, com papéis, exceções e contas intactos;
  - causas vindas de `CAUSES`, sem quantidade nem lista fixa;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\UserAccessRevocationTest`** (MariaDB, banco `*_test`):
  - papéis e exceções de verdade;
  - a prévia, as recusas com tudo desfeito e a saída gravada, com as duas auditorias;
  - o acesso a outro condomínio fica;
  - rodar de novo não muda nada;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
