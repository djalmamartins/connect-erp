# 0025 — Reset do 2FA de outro usuário por administrador

Data: 2026-11-08 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

Risco 4 da revisão de segurança da Fase 1 (issue #7): a permissão `security.2fa.manage` existe
desde a migration `20261107_0003`, mas não havia como usá-la. Quem perde o celular depende de um
reset manual no banco, sem auditoria.

## Decisão

- **Endpoint `DELETE /api/v1/users/{id}/2fa`** (`Api\V1\UserTwoFactor`, sessão + CSRF), que reusa
  `TwoFactorService::disable($alvo, $quemPede)`: apaga segredo e códigos de recuperação. No
  próximo login o usuário cai no cadastro do autenticador, se o 2FA for obrigatório.
- **Regras** em `Source\Services\TwoFactorAdminReset`, sem banco:
  - exige `security.2fa.manage` (prefixo estrito, nega sem cadastro);
  - não vale para o próprio usuário: ele desativa pela tela de segurança, com código;
  - não vale para alvo com nível legado maior que o de quem pede;
  - alvo sem 2FA ativo responde 409.
- **Auditoria**: `system_audit_logs` (`Audit::record`, entidade `user_two_factor`), `app_log` e o
  log `TWO_FACTOR_DISABLED` com `actor_id`. Recusa por permissão ou nível grava
  `TWO_FACTOR_RESET_DENIED`.
- A tela fica para a frente de layout (issue #10), consumindo este endpoint.

## Consequências

- Sessões já abertas do alvo continuam válidas; o reset vale a partir do próximo login.
- O nível legado é a única hierarquia hoje. Quando os papéis tiverem hierarquia própria, trocar a
  comparação de nível por ela.
- Testes: `TwoFactorAdminResetTest` (regras e rota). Integração com MariaDB fica para a issue #2.
