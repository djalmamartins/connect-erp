# Organic Code

Base modular PHP 8.2+ e MovesCode, derivada de uma auditoria do Realizze 2.0.

## Estado

Repositório inicializado. A configuração local, migração seletiva e testes ainda não foram executados.

## Princípios

- Ambientes isolados: `container/{web,app,cms,erp,send}`.
- Temas versionados: apenas `default.php` e `routes.php` na raiz.
- Sem segredos versionados.
- Migração por seleção, nunca sobrescrever o Realizze.
- GitHub PR e testes antes de integrar na `main`.

Documentação e instalador serão adicionados em commits subsequentes.
