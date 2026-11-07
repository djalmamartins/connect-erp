# Decisão arquitetural V3 — MariaDB + API REST

**Decisão confirmada pelo proprietário:** MariaDB substitui PostgreSQL como banco-alvo; API REST substitui ConnectRPC. Organic Code continua sendo a base. Inspecionar o código real antes de qualquer modificação.

## Persistência
- Reutilizar a camada de acesso a dados existente, avaliando compatibilidade PDO/MySQL e versões do MariaDB efetivamente instaladas.
- Migrations versionadas, chaves estrangeiras, índices, transações InnoDB, constraints suportadas, isolamento por condomínio (`condominium_id`, administradora única — ADR-002) e rollback ou reversão planejada.
- Valores monetários DECIMAL, nunca FLOAT; datas em UTC e exibição por fuso; charset utf8mb4.
- Credenciais em variáveis de ambiente; backups testados e restore automatizado em homologação.
- Não migrar banco existente ou executar SQL destrutivo sem autorização.

## API REST desde a primeira funcionalidade
- Toda fatia vertical deve entregar simultaneamente modelo/migration, serviço de domínio, endpoint REST versionado, autorização, UI, documentação OpenAPI e testes.
- Prefixo sugerido `/api/v1`, condicionado à inspeção do router existente.
- JSON consistente, códigos HTTP adequados, validação no servidor, paginação, filtros, ordenação com allowlist, tratamento padronizado de erros e IDs opacos.
- Contratos OpenAPI versionados e testes de contrato; autenticação aproveita o núcleo existente (sem criar sistema paralelo).
- Operações financeiras: idempotency-key, transações, controle de concorrência, auditoria e reconciliação.
- CORS restritivo, rate limits, CSRF quando sessão/cookies, proteção de dados e verificação de escopo em todas as consultas.
- Não expor APIs internas do banco nem informações de outros condomínios.

## Entregas por módulo
1. Verificar repositório, esquema e API existentes; criar ADR se houver conflitos.
2. Implementar primeira fatia vertical (condomínios) com MariaDB + REST + UI + testes.
3. Repetir o mesmo padrão para unidades, pessoas, financeiro, cobranças, relatórios etc.
4. Integrações bancárias usam adaptadores e jobs/webhooks próprios; nunca chamar bancos diretamente do frontend.
