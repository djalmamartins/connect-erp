# ADR 0380 — Comando do primeiro administrador

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0379 (instalação limpa), ADR 0023/0028 (2FA), ADR 0354 (deploy-check sem conta de teste)

## Contexto

Na instalação limpa (ADR 0379) o banco não tem nenhuma conta, e o `deploy-check` recusa conta de teste. Não havia jeito
seguro de criar a primeira conta da equipe sem mexer no banco à mão.

## Decisão

`php tools/hosting/criar-admin.php --email= --nome= --sobrenome= --cpf=` (só pela linha de comando):

- só funciona enquanto **nenhuma** conta tem o papel `super_admin` ou `developer` (depois, as contas saem pela tela
  Usuários do Sistema); não vira porta dos fundos;
- confere e-mail, nome, sobrenome e CPF; a conta nasce confirmada, nível 10, papel `super_admin`, e o papel recebe todas as
  permissões cadastradas pelas migrations;
- senha aleatória de 20 caracteres (maiúscula, minúscula, número e símbolo), mostrada uma vez só, guardada com
  `password_hash`; o 2FA é cobrado no primeiro login pelo `CONNECT_2FA_ENFORCE_FROM`;
- protegida contra exclusão em `system_protected_users` quando essa tabela existe (o `User::destroy` já protege o id 1);
- tudo numa transação, com registro na auditoria.

## Testes

`FirstAdminTest` (integração): cria a conta com papel, todas as permissões, proteção e senha forte que confere com o hash;
recusa o segundo administrador, um `developer` existente, dados inválidos e papel ausente. Comando rodado no banco de
teste: recusa com saída 1 quando já há administrador.
