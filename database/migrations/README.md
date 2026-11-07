# Migrations

Migrations serão versionadas aqui e executadas por uma ferramenta compatível com
a camada Model/Storage do MovesCode após a importação do runtime. Não criar
tabelas de usuários, permissões ou logs: esses módulos devem ser reutilizados.

Regras: InnoDB, `utf8mb4`, chaves estrangeiras, `DECIMAL` para valores,
transações e reversão documentada. Nenhuma migration roda automaticamente.
