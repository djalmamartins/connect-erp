---
name: rest-api-development
description: Definir contratos OpenAPI e implementar APIs REST versionadas (/api/v1) junto com cada funcionalidade do Connect ERP.
---
# REST por fatia vertical
1. Ler `docs/architecture/MARIADB_REST.md` e inspecionar o router (MovesCode Router via `Source\Core\Router`) e a autenticação existentes.
2. Escrever o contrato em `docs/api/openapi.yaml` antes de expor o endpoint: JSON consistente, status HTTP, erros padronizados, paginação, filtros e ordenação com allowlist, IDs opacos.
3. Autorização server-side por permissão e por condomínio (ADR-002); CSRF quando houver sessão/cookie; rate limit.
4. Operações financeiras com idempotency-key, transação e auditoria.
5. Não criar endpoint sem serviço de domínio e testes de contrato, autorização e isolamento entre condomínios.
