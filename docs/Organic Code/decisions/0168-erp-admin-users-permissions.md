# 0168 — Administração, relatórios enviados, usuários e permissões do ERP

Data: 2026-11-10 · Status: proposto para revisão · Continua o inventário do ADR 0164

## Contexto

Quatro páginas de exemplo ainda não tinham backend: AP-091 Administração, AP-100 Relatórios Enviados, AP-149
Usuários do Sistema e AP-150 Permissões de Usuários. O controle de acesso já existe: `access_roles`,
`access_permissions`, `access_role_permissions`, `access_user_overrides`, `user_condominium_access`,
`Access::can` e `CondominiumScope`. O Studio já cria contas e troca papéis (Usuários). Esta entrega
reaproveita isso e não cria estrutura nova.

## Decisão

- **AP-091 Administração** (`AdminReports::overview`, `GET /api/v1/erp-admin/overview`, permissão
  `erp.settings.view`):
  - os dados da administradora vêm dos parâmetros AP-147 (nome exibido, e-mail e telefone de atendimento),
    do padrão da Connect ou, sem valor, do padrão do código;
  - o resumo do escopo traz condomínios, unidades, moradores, usuários com acesso, contas bancárias e os
    fechamentos do mês anterior.
- **AP-100 Relatórios Enviados** (`AdminReports::sent`, `GET /api/v1/reports/sent`, permissão
  `erp.audit.view`):
  - lê da auditoria as ações `export` em entidades `erp_*`, que são as mesmas que contam o limite de 30 CSV
    por hora;
  - quem acessa todos os condomínios vê tudo; os demais veem só as exportações de condomínios do seu escopo e
    as próprias exportações sem condomínio;
  - o IP vem mascarado e o nome passa pelo `AuditMask`;
  - o período vai até o limite do `CollectionReports::period`, com até 5000 linhas e `truncated`.
- **AP-149 Usuários do Sistema** (`ErpAccess`; permissões `erp.users.view` e `erp.users.manage`):
  - **listar**: as contas com acesso a condomínios do escopo, com o e-mail mascarado, o papel e o campo
    `manageable`;
  - **convidar**: dá acesso a condomínios do escopo para uma conta que já existe e cujo papel já tem
    `erp.access`. Sem conta, a resposta é 422 e a conta é criada no Studio. O ERP não cria conta, não troca
    papel e não mexe em senha, login nem 2FA, porque os papéis `manager` e `operator` também carregam
    permissões do Studio. Trocar papel pelo ERP daria acesso ao Studio sem passar pela tela de Usuários.
  - **desativar**: tira o acesso a um condomínio ou a todos do escopo. A conta continua existindo.
- **AP-150 Permissões de Usuários** (`ErpAccess`; permissões `erp.permissions.view` e
  `erp.permissions.manage`):
  - a matriz mostra os papéis visíveis (nível até o de quem consulta, sem `developer`) e as permissões
    `erp.*`;
  - mudar uma permissão de papel exige acesso a todos os condomínios, porque o papel vale para todos;
  - a exceção por usuário (`allow`, `deny` ou `inherit`) vale para um usuário do escopo.
- **Regras fail-closed**:
  - mexer só em papel de nível menor que o seu, nunca em `developer`/`super_admin`, nunca em si mesmo (o
    desenvolvedor mexe em todos menos em si);
  - só permissões `erp.*` e cadastradas;
  - `erp.condominiums.all` só pelo desenvolvedor, porque muda o escopo;
  - não se concede o que não se tem (tirar não exige ter);
  - um usuário sem condomínio no escopo de quem administra responde 404, mesmo que exista (no convite, uma
    conta sem condomínio nenhum pode ser convidada);
  - tudo é auditado (`user_condominium_access`, `access_role_permissions`, `access_user_overrides`) e limpa o
    cache do `Access`.
- **Migration 20261109_0705** (com down): cadastra as 4 permissões novas e dá só para `developer` e
  `super_admin`. Os demais papéis recebem pelo Studio ou pela AP-150.
- **Erros**: fora do escopo, 404; inválido, 422; sem poder, 403; nada a desativar, 409. O CSRF fica no
  `ApiAuthenticated`.

## Consequências

- `ErpAccessAdminTest` (5 testes) cobre:
  - as regras puras;
  - listar, convidar e desativar (escopo, nível, si mesmo, conta de outro escopo, papel sem ERP, papel que não
    muda e 409);
  - a matriz e a mudança de papel (global, protegido, escopo de todos, não concede o que não tem, 422 e o
    desenvolvedor);
  - as exceções por usuário (deny, allow e inherit, 403 e 404);
  - a administração e os relatórios enviados (escopo, filtro, máscara e 422);
  - a permissão nos controllers e a migration fail-closed.
- O convite revela a quem tem `erp.users.manage` se um e-mail tem conta: 422 sem conta, 404 se for de outro
  escopo. Isso foi aceito porque o recurso só existe para administradores.
- Nenhuma página nova no catálogo e nenhuma alteração em login, 2FA, Email ou Studio.
