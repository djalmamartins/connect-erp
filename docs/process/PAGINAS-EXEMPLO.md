# Páginas do ERP ainda em demonstração

Inventário feito no ciclo 53 (ADR 0164), só leitura, em 2026-11-10, sobre o worktree connect-claude-units.

## Atualização de 10/11/2026 (ADR 0143)

Conferido na main: 24 páginas da tabela já tinham tela real quando o inventário foi feito (AP-030, AP-039,
AP-047, AP-048, AP-057 a AP-061, AP-071, AP-072, AP-102 a AP-109, AP-116, AP-117, AP-141, AP-167 e AP-171).
Com o ADR 0143, mais 36 passaram a ler dados reais pela tela única de relatórios (`ReportScreens`): AP-045,
AP-046, AP-049, AP-050, AP-052 a AP-054, AP-056, AP-062 a AP-070, AP-073 a AP-076, AP-080, AP-082, AP-083, AP-085 a AP-087,
AP-089, AP-090 e AP-093 a AP-099. Com o ADR 0144, as configurações AP-147, AP-151, AP-154, AP-155,
AP-157 a AP-159, AP-162, AP-163, AP-166 e o relatório AP-084 também. Com o ADR 0145, as assembleias
AP-017, AP-018, AP-088 e AP-092. Com o ADR 0146, as telas do ciclo 55
(AP-101, AP-136, AP-161). Com o ADR 0147, os acessos AP-148, AP-152 e AP-153. Do que
tinha API pronta, não sobra página de exemplo; seguem na demonstração só as que ainda precisam de backend.

## Critério

Entram as páginas do `catalog.php` que ainda mostram dados fixos ou fictícios. São as que não são cadastro do motor
(`Definitions`) e não têm tela real no `Panel::ownTemplate` nem nos controllers do ERP. Essas páginas caem num dos
modelos de `pages/modules/*` com os dados de `DemoData` (fixtures em `database/fixtures/erp-demo`) e não chamam
API.

Coluna "Backend que já existe": service ou controller que já cita o código da página. "O que falta":
- **ligar a tela** quando a API já existe. É trabalho da frente de layout: trocar o modelo de demonstração por
  uma tela que chama a API;
- **backend** quando ainda não há service nem API.

Total: 88 páginas.

| Página | O que mostra | Backend que já existe | O que falta |
|---|---|---|---|
| AP-030 Fechamento Mensal (financeiro) | checklist de fechamento de exemplo (pages/modules/closing.php) | `Services/Erp/MonthClosing.php`, `Controllers/Api/V1/MonthClosings.php` | ligar a tela à API existente |
| AP-031 Pagamentos (Bancos) (financeiro) | lista com registros de demonstração (pages/modules/list.php) | — | backend (service e API) e depois ligar a tela |
| AP-117 Atendimento Cobrança (cobranca) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/Collections.php`, `Controllers/Api/V1/Collections.php` | ligar a tela à API existente |
| AP-037 Remessas (cobranca) | lista com registros de demonstração (pages/modules/list.php) | — | backend (service e API) e depois ligar a tela |
| AP-039 Cobrança Judicial (cobranca) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/Collections.php`, `Controllers/Api/V1/Collections.php` | ligar a tela à API existente |
| AP-116 Multas Pendentes (cobranca) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/Collections.php`, `Services/Erp/EntryShortcuts.php`, `Controllers/Api/V1/Collections.php` | ligar a tela à API existente |
| AP-101 Prestação de contas (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `GET /api/v1/reports/accountability` (ADR 0166) | ligar a tela (contrato no ADR 0166 e no docs/api/openapi.yaml) |
| AP-094 Listagem de prestações de contas (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-052 Demonstrativo Contas (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-053 Demonstrativo Analítico (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-054 Demonstrativo Situação (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-056 Extrato Conta (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-080 Balancete Anual (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/AnnualBalance.php`, `GET /api/v1/reports/annual-balance` (ciclo 53) | ligar a tela (contrato em docs/handoff-0164-shared.md) |
| AP-081 Balanço Patrimonial (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | — | natureza patrimonial no plano de contas (ADR 0165); depois o backend |
| AP-082 Demonstração do resultado (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/AccountingReports.php`, GET /api/v1/reports/income-statement (ciclo 54) | ligar a tela (contrato no ADR 0165 e no docs/api/openapi.yaml) |
| AP-083 Livro Razão (prestacao-contas) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/AccountingReports.php`, GET /api/v1/reports/ledger (ciclo 54) | ligar a tela (contrato no ADR 0165 e no docs/api/openapi.yaml) |
| AP-136 Registros de Entregas (operacional) | lista com registros de demonstração (pages/modules/list.php) | `GET /api/v1/reports/deliveries` e `POST /api/v1/delivery-pickups` (ADR 0166) | ligar a tela (contrato no ADR 0166 e no docs/api/openapi.yaml) |
| AP-018 Assembleia Virtual (comunicacao) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/Assemblies.php` | ligar a tela à API existente |
| AP-017 Votação (comunicacao) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/Assemblies.php` | ligar a tela à API existente |
| AP-102 Gráficos gerais (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-103 Gráfico de contas (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-104 Gráfico de consumo (unidade) (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-105 Gráfico de consumo (geral) (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-106 Gráfico financeiro (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-107 Gráfico de inadimplência (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-108 Gráfico financeiro por conta (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-109 Gráfico de áreas comuns (relatorios) | gráfico com série fixa (pages/modules/chart.php) | `Services/Erp/ChartReports.php`, `Controllers/Api/V1/ChartReports.php` | ligar a tela à API existente |
| AP-045 Consumo (Geral) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-046 Consumo (Unidade) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-047 Relatório de acordos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-048 Taxas de Acordos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-049 Relatório de centros de custo (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-050 Comparativo (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-057 Inadimplentes (Geral) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-058 Inadimplentes (Unidade) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-059 Inadimplentes (Detalhado) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-060 Inadimplentes (Taxa) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-061 Inadimplentes (Morador) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-062 Rateio (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-063 Rateio Comparativo (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-064 Rateio Detalhado (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-065 Rateio Analítico (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-066 Rateio Analítico (Detalhado) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-067 Rateio por Conta (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-068 Relatório de lançamentos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-069 Relatório de lançamentos (detalhado) (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php` | ligar a tela à API existente |
| AP-070 Relatório de boletos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ManagementReports.php`, `Controllers/Api/V1/ManagementReports.php` | ligar a tela à API existente |
| AP-071 Recebimentos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/CollectionReports.php` | ligar a tela à API existente |
| AP-072 Recebimentos por Conta (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/CollectionReports.php` | ligar a tela à API existente |
| AP-073 Saldos de Contas (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/AccountingReports.php`, GET /api/v1/reports/account-balances (ciclo 54) | ligar a tela (contrato no ADR 0165 e no docs/api/openapi.yaml) |
| AP-074 Registro de Pagamentos (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/PaymentReports.php`, `Controllers/Api/V1/PaymentReports.php` | ligar a tela à API existente |
| AP-075 Relatório Memória de Cálculo (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/PaymentReports.php`, `Controllers/Api/V1/PaymentReports.php` | ligar a tela à API existente |
| AP-076 Relatório de transferências (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/PaymentReports.php`, `Controllers/Api/V1/PaymentReports.php` | ligar a tela à API existente |
| AP-084 Relatório de previsão orçamentária (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/ErpSettings.php`, `Controllers/Api/V1/ErpSettings.php` | ligar a tela à API existente |
| AP-085 Relatório de reservas (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-086 Relatório de unidades (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-087 Listagem de Pessoas (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Services/Erp/CollectionReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-088 Listagem de presenças (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/Assemblies.php`, `Controllers/Api/V1/Assemblies.php` | ligar a tela à API existente |
| AP-089 Relatório de condomínios (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-090 Relatório de ocorrências (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-091 Administração (relatorios) | relatório com linhas fixas (pages/modules/report.php) | — | backend (service e API) e depois ligar a tela |
| AP-092 Parecer (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/Assemblies.php`, `Controllers/Api/V1/Assemblies.php` | ligar a tela à API existente |
| AP-093 Pagamentos Pendentes (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-095 Mudanças (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-096 Relatório de Endereços (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-097 Logs (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-098 Pessoas com Acesso (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-099 Boletos Enviados (relatorios) | relatório com linhas fixas (pages/modules/report.php) | `Services/Erp/RegistryReports.php`, `Controllers/Api/V1/RegistryReports.php` | ligar a tela à API existente |
| AP-100 Relatórios Enviados (relatorios) | relatório com linhas fixas (pages/modules/report.php) | — | backend (service e API) e depois ligar a tela |
| AP-141 Importação NF-e (xml) (importacoes) | pré-visualização de importação de exemplo (pages/modules/import.php) | `Services/Erp/Imports/NfeParser.php`, `Services/Erp/Imports/Imports.php` | ligar a tela à API existente |
| AP-147 Administrativo (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-149 Usuários do Sistema (configuracoes) | lista com registros de demonstração (pages/modules/list.php) | — | backend (service e API) e depois ligar a tela |
| AP-150 Permissões de Usuários (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | — | backend (service e API) e depois ligar a tela |
| AP-153 Permissões de Moradores (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ResidentPortalPermissions.php`, `Controllers/Api/V1/AccessAdmin.php` | ligar a tela à API existente |
| AP-148 Usuários de imobiliárias (configuracoes) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/PartnerAccess.php`, `Controllers/Api/V1/AccessAdmin.php` | ligar a tela à API existente |
| AP-152 Criação de usuários para moradores (configuracoes) | formulário de exemplo (pages/modules/form.php) | `Services/Erp/ResidentInvitations.php`, `Services/Erp/ResidentInvitationAcceptance.php`, `Controllers/Api/V1/AccessAdmin.php` | ligar a tela à API existente |
| AP-151 Notificações da prestação de contas (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-154 Layout da Aplicação (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-155 Notificações aplicativo (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-159 Alertas (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-157 Financeiro (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-158 Demonstrativo (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-161 Saldo inicial (plano de contas) (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `GET/POST /api/v1/chart-account-openings` (ADR 0166) | ligar a tela (contrato no ADR 0166 e no docs/api/openapi.yaml) |
| AP-162 Saldo inicial (centro de custo) (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php`, `Controllers/Api/V1/ErpSettings.php` | ligar a tela à API existente |
| AP-163 Previsão Orçamentária (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php`, `Controllers/Api/V1/ErpSettings.php` | ligar a tela à API existente |
| AP-166 Parâmetros (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php` | ligar a tela à API existente |
| AP-167 Fração Ideal (configuracoes) | formulário de configuração de exemplo (pages/modules/settings.php) | `Services/Erp/ErpSettings.php`, `Controllers/Api/V1/ErpSettings.php` | ligar a tela à API existente |
| AP-171 Integrações (configuracoes) | lista com registros de demonstração (pages/modules/list.php) | `Services/Erp/IntegrationStatus.php`, `Controllers/Api/V1/IntegrationStatus.php` | ligar a tela à API existente |
