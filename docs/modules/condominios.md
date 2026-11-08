# Módulo Condomínios (ERP v1)

Referência funcional: `docs/specs/pages/ap-004-condominios.md` (campos ainda PENDENTE).
Decisões: ADR-002 (administradora única), ADR 0006 (autorização), ADR 0007 (tema e fatia).

## Entregue (2026-11-07)

| Item | Implementação |
|---|---|
| Rotas web | `GET /erp`, `GET /erp/condominios`, `GET /erp/condominios/{id}` (`container/erp/connect/v1/routes.php`) |
| API | `GET /api/v1/condominiums`, `GET /api/v1/condominiums/{id}` (já existentes) |
| Controller | `Source\Controllers\Erp\Connect\Condominiums` |
| Serviço | `Source\Services\CondominiumScope` (único ponto de escopo, web e API) |
| Dados | `condominiums`, `user_condominium_access` (migration `20261107_0002`) |
| Permissões | `erp.access`, `erp.condominiums.view`, `erp.condominiums.all` (migration `20261107_0003`) |
| Páginas | `pages/condominiums/{index,show}.php`, `pages/errors/state.php` |
| Estados | lista, vazio, detalhe, 403 acesso negado, 404 fora do escopo |

## Pendente

- Cadastro e edição (`erp.condominiums.manage`): campos, validação de CNPJ, CSRF, auditoria
  via `Support/Audit` e testes negativos, depois da spec AP-004.
- Teste de integração HTTP das rotas `/erp` e smoke no lab.
- Visual definitivo e bundle Compress (`claude-layout`).
