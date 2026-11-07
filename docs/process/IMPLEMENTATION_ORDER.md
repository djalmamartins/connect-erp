# Ordem de desenvolvimento recomendada
0. Auditoria do repositório e compatibilidade com usuários/permissões/logs existentes.
1. MariaDB, migrations, isolamento e contratos de domínio; validar API REST por ADR/POC.
2. Cadastros de condomínios, unidades, pessoas, vínculos, veículos e pets; componentes de UI compartilhados.
3. Plano de contas, centros de custo, contas bancárias, lançamentos e contas a pagar/receber.
4. Faturas e motor de cobrança com estados e idempotência.
5. Adaptador bancário piloto em sandbox, boleto, eventos, remessa/retorno e conciliação.
6. Relatórios financeiros e prestação de contas com reconciliação.
7. Operacional, portaria, documentos, leituras, dashboards, demais relatórios e integrações.
Cada etapa só fecha com teste de autorização, tenant, acessibilidade, fluxo e regressão.
