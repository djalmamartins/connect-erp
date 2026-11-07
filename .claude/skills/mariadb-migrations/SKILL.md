---
name: mariadb-migrations
description: Modelar tabelas e escrever migrations MariaDB versionadas e reversíveis para cada módulo do Connect ERP, com testes de instalação limpa.
---
# Modelagem e migrations MariaDB
1. Inspecionar o esquema real e as migrations existentes (`database/legacy-migrations/`, `database/migrations/`) antes de propor qualquer tabela. Não sobrescrever tabelas existentes sem auditoria.
2. Padrões: InnoDB, utf8mb4 (`utf8mb4_unicode_ci`), `DECIMAL(15,2)` para dinheiro (nunca FLOAT), datas em UTC, FKs e índices adequados, constraints suportadas pelo MariaDB.
3. Segregação: todo dado de condomínio tem `condominium_id` com FK e índice composto; administradora única, sem `tenant_id` (ADR-002).
4. Cada migration tem avanço e reversão (ou plano de reversão documentado), é idempotente quando possível e roda dentro de transação quando o DDL permitir.
5. Testar em banco descartável: instalação limpa, aplicação sobre o esquema atual e reversão. Operações financeiras usam transações.
6. Nunca executar migration destrutiva ou em produção sem aprovação explícita.
Saída: evidência do esquema atual, migration, testes executados, riscos e atualização da spec.
