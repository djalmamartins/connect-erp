<?php
ob_start();
require __DIR__ . "/vendor/autoload.php";

/**
 * BOOTSTRAP
 */

use Source\Core\Router;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Support\Access;
use Source\Support\AppLogger;

$logger = AppLogger::bootstrap();
$session = new Session();
$route = new Router(url(), ":");

$route->namespace(null);
$route->group("/api/v1");
$route->get("/health", static function (): void {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    try {
        \Source\Core\Connect::getInstance()->query("SELECT 1")->fetchColumn();
        echo json_encode(["data" => ["service" => "connect-web-cms", "status" => "ready", "checks" => ["database" => "ready"]]], JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        http_response_code(503);
        echo json_encode(["data" => ["service" => "connect-web-cms", "status" => "degraded", "checks" => ["database" => "unavailable"]]], JSON_THROW_ON_ERROR);
    }
});

/**
 * API REST v1 (sessão do Organic Code; 2FA obrigatório quando ativo)
 */
$route->namespace("Source\\Controllers\\Api\\V1");
$route->post("/auth/2fa/verify", "TwoFactor:verify");
// Sessão do app nativo do morador (ADR 0303): token Bearer, sem cookie nem CSRF; login com o mesmo limite de
// tentativas e o mesmo 2FA do login web.
$route->post("/resident/app-sessions", "ResidentAppSessions:store");
$route->post("/resident/app-sessions/verify", "ResidentAppSessions:verify");
$route->delete("/resident/app-sessions/current", "ResidentAppSessions:destroy");
// Webhook de cobrança do banco (ADR 0156): sem sessão; protegido pelo segredo do endereço e pela
// consulta de cada título no banco antes de mudar qualquer estado.
$route->post("/webhooks/bank/{adapter}/{secret}", "BankWebhook:receive");
$route->get("/me", "Me:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums", "Condominiums:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums/{id}", "Condominiums:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums/{id}/onboarding", "CondominiumOnboarding:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding", "CondominiumOnboarding:start", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding/items", "CondominiumOnboarding:add", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/condominiums/{id}/onboarding/items/{item}", "CondominiumOnboarding:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Renovação em lote do checklist de chegada (ADR 0219).
$route->post("/condominiums/{id}/onboarding/renew", "OnboardingRenewal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/onboarding/templates", "OnboardingTemplates:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/onboarding/templates", "OnboardingTemplates:create", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/onboarding/templates/{id}", "OnboardingTemplates:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reset do 2FA de outro usuário por quem tem security.2fa.manage (ADR 0025).
$route->delete("/users/{id}/2fa", "UserTwoFactor:reset", null, \Source\Support\Middleware\ApiAuthenticated::class);

// Saldo calculado das contas bancárias (ADR 0024). Antes do laço dos cadastros: /bank-accounts/{id}
// capturaria "balances".
$route->get("/bank-accounts/balances", "BankBalances:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Geração dos lançamentos do mês a partir dos lançamentos fixos (ADR 0027).
$route->post("/recurring-entries/generate", "RecurringEntriesGenerate:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reajuste de lançamentos fixos em lote (ADR 0204). Antes do laço dos cadastros: /recurring-entries/{id} capturaria "readjust".
$route->post("/recurring-entries/readjust", "RecurringReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Fechamento mensal (ADR 0031): resumo, fechar e reabrir a competência de um condomínio.
$route->get("/closings", "MonthClosings:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/closings", "MonthClosings:close", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/closings/reopen", "MonthClosings:reopen", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/closings/readiness", "ClosingReadiness:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Fechamento do mês em lote, pela conferência (ADR 0205).
$route->post("/closings/batch", "MonthClosingBatch:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Faturas (ADR 0033): preparar, ajustar item, emitir (gera contas a receber) e cancelar.
$route->get("/invoices", "Invoices:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices", "Invoices:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/invoices/{id}", "Invoices:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/invoices/{id}/items/{item}", "Invoices:updateItem", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices/{id}/issue", "Invoices:issue", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices/{id}/cancel", "Invoices:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Boletos, remessas, retorno e fila de divergências (ADR 0035 e 0156): sandbox interno ou Inter (sandbox do banco).
$route->get("/bank-charges", "Banking:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges", "Banking:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-charges/{id}", "Banking:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges/{id}/refresh", "Banking:refresh", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges/{id}/cancel", "Banking:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-charges/{id}/pdf", "Banking:pdf", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-remittances", "Banking:remittances", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Credencial do banco pela tela Integrações (ADR 0158): pacote do banco, teste e cofre; só sandbox.
$route->get("/bank-credentials", "BankCredentialsApi:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-credentials/{id}", "BankCredentialsApi:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-credentials/{id}/test", "BankCredentialsApi:test", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->delete("/bank-credentials/{id}", "BankCredentialsApi:destroy", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-returns", "Banking:returns", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-returns", "Banking:importReturn", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-events/divergences", "Banking:divergences", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-events/{id}/resolve", "Banking:resolve", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios financeiros (ADR 0037): balancete de verificação e prestação de contas com conciliação.
$route->get("/reports/trial-balance", "FinancialReports:trialBalance", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0164: Balancete Anual (AP-080)
$route->get("/reports/annual-balance", "AnnualBalance:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/annual-accountability", "AnnualAccountability:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/unit-statement", "UnitStatement:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/registry-health", "RegistryHealth:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/cash-forecast", "CashForecast:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/expense-review", "ExpenseReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/supplier-spend", "SupplierSpend:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/billing-check", "BillingCheck:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/collection-progress", "CollectionProgress:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/supplier-health", "SupplierHealth:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/expense-benchmark", "ExpenseBenchmark:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0165: relatórios contábeis
$route->get("/reports/ledger", "AccountingReports:ledger", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/account-balances", "AccountingReports:accountBalances", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/income-statement", "AccountingReports:incomeStatement", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0166: prestação de contas, registros de entregas e saldo inicial do plano de contas
$route->get("/reports/accountability", "Accountability:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/accountability-publications", "AccountabilityPublications:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/accountability-publications/{id}", "AccountabilityPublications:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/accountability-publications", "AccountabilityPublications:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/accountability-publications/{id}/withdraw", "AccountabilityPublications:withdraw", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Link público da prestação de contas (ADR 0301): gerar, enviar aos moradores e revogar.
$route->get("/accountability-links", "AccountabilityLinks:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/accountability-links", "AccountabilityLinks:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/accountability-links/{id}/send", "AccountabilityLinks:send", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/accountability-links/{id}/revoke", "AccountabilityLinks:revoke", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/deliveries", "DeliveryLog:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/delivery-pickups", "DeliveryLog:pickUp", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/chart-account-openings", "ChartOpenings:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/chart-account-openings", "ChartOpenings:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0167: Balanço Patrimonial (AP-081)
$route->get("/reports/balance-sheet", "BalanceSheet:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Livro caixa mensal (CN-007, ADR 0174).
$route->get("/reports/cash-book", "CashBook:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0168: administração, relatórios enviados, usuários e permissões do ERP (/erp-users/invite antes de /erp-users/{id})
$route->get("/erp-admin/overview", "ErpAdmin:overview", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/sent", "ErpAdmin:sentReports", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/erp-users", "ErpAdmin:users", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/erp-users/invite", "ErpAdmin:invite", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/erp-users/{id}/deactivate", "ErpAdmin:deactivate", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/erp-users/{id}/permissions", "ErpAdmin:userPermissions", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/erp-users/{id}/permissions", "ErpAdmin:setUserPermission", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/erp-permissions", "ErpAdmin:permissions", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/erp-permissions", "ErpAdmin:setRolePermission", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0169: lançamentos patrimoniais com partida dobrada (fundos e PL)
$route->get("/patrimonial-entries", "PatrimonialEntries:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/patrimonial-entries", "PatrimonialEntries:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/patrimonial-entries/{id}/reverse", "PatrimonialEntries:reverse", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0170: aplicação financeira ligada à conta corrente (/investments/positions antes de /investments/{id})
$route->get("/investments/positions", "Investments:positions", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/bank-ledger", "Investments:bankLedger", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/investments/{id}/link", "Investments:link", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/investments/{id}/apply", "Investments:apply", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/investments/{id}/redeem", "Investments:redeem", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0171: importação de lançamentos patrimoniais (CSV/XLSX) com prévia e aplicação tudo ou nada
$route->post("/patrimonial-imports", "PatrimonialImports:preview", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/patrimonial-imports/{id}", "PatrimonialImports:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/patrimonial-imports/{id}/apply", "PatrimonialImports:apply", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/statement", "FinancialReports:statement", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios de cobrança (ADR 0081): acordos, taxas de acordos, inadimplentes e recebimentos; ?format=csv.
$route->get("/reports/agreements", "CollectionReports:agreements", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/agreement-watch", "AgreementWatch:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/operations-summary", "OperationsSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Conferência das cópias de cheques (ADR 0214), somente leitura.
$route->get("/reports/check-copy-review", "CheckCopyReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Documentos em trânsito: malotes e avisos de recebimento parados (ADR 0215), somente leitura.
$route->get("/reports/document-transit", "DocumentTransit:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Calendário de vencimentos: seguro, contrato, mandato e checklist (ADR 0218), somente leitura.
$route->get("/reports/obligation-calendar", "ObligationCalendar:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Conferência das leituras de consumo do mês (ADR 0220), somente leitura.
$route->get("/reports/meter-reading-review", "MeterReadingReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inadimplência por bloco ou andar (ADR 0222), somente leitura.
$route->get("/reports/delinquency-by-location", "DelinquencyByLocation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Quadro de funcionários e folha por função e turno (ADR 0224), somente leitura.
$route->get("/reports/employee-headcount", "EmployeeHeadcount:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Tempo de atendimento dos chamados do condomínio (ADR 0226), somente leitura.
$route->get("/reports/ticket-service-times", "TicketServiceTimes:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/agreement-fees", "CollectionReports:agreementFees", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/delinquents", "CollectionReports:delinquents", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/receipts", "CollectionReports:receipts", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios gerenciais (ADR 0082): consumo, centros de custo, comparativo, demonstrativos, extrato, rateio, lançamentos e boletos.
$route->get("/reports/consumption", "ManagementReports:consumption", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/cost-centers", "ManagementReports:costCenters", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/comparison", "ManagementReports:comparison", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/financial-statement", "ManagementReports:statement", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/account-statement", "ManagementReports:accountStatement", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/apportionment", "ManagementReports:apportionment", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/entries", "ManagementReports:entries", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/bank-charges", "ManagementReports:bankCharges", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/payments", "PaymentReports:payments", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/calculation", "PaymentReports:calculation", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/transfers", "PaymentReports:transfers", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Dados dos gráficos (ADR 0083): séries mensais, só consulta.
$route->get("/charts/general", "ChartReports:general", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/accounts", "ChartReports:accounts", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/consumption", "ChartReports:consumption", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/cash", "ChartReports:cash", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/delinquency", "ChartReports:delinquency", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/bank-accounts", "ChartReports:bankAccounts", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/charts/common-areas", "ChartReports:commonAreas", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios cadastrais e administrativos (ADR 0084); ?format=csv.
$route->get("/reports/reservations", "RegistryReports:reservations", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/units", "RegistryReports:units", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/people", "RegistryReports:people", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/condominiums", "RegistryReports:condominiums", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/occurrences", "RegistryReports:occurrences", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/pending-payments", "RegistryReports:pendingPayments", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/closings", "RegistryReports:closings", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/moves", "RegistryReports:moves", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/addresses", "RegistryReports:addresses", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/logs", "RegistryReports:logs", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/access-people", "RegistryReports:accessPeople", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/sent-boletos", "RegistryReports:sentBoletos", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Configurações do ERP (ADR 0085): parâmetros, saldo inicial por centro de custo, previsão orçamentária e frações em lote.
$route->get("/settings", "ErpSettings:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/settings", "ErpSettings:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/settings/reset", "ErpSettings:reset", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/integrations", "IntegrationStatus:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/cost-center-openings", "ErpSettings:openings", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/cost-center-openings", "ErpSettings:storeOpening", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/budgets", "ErpSettings:budget", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/budgets", "ErpSettings:storeBudget", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Proposta de previsão orçamentária a partir do realizado (ADR 0187).
$route->get("/budgets/proposal", "BudgetProposal:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/budgets/proposal", "BudgetProposal:apply", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Simulação da taxa condominial a partir da previsão (ADR 0189).
$route->get("/budgets/fee-simulation", "FeeSimulation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/unit-fractions", "ErpSettings:fractions", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inadimplência e acordos (ADR 0039): contas vencidas por unidade, acordo com parcelas e cancelamento.
$route->get("/delinquency", "Agreements:delinquency", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/agreements", "Agreements:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/agreements", "Agreements:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encargos sugeridos e quebra de acordo (ADR 0040). /suggestion antes de /{id}.
$route->get("/agreements/suggestion", "Agreements:suggestion", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/agreements/{id}", "Agreements:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/agreements/{id}/cancel", "Agreements:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/agreements/{id}/breach", "Agreements:breach", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Regras de cobrança (multa, juros, índice, quebra) e índices mensais de correção (ADR 0040).
$route->get("/billing-rules", "BillingRules:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->put("/billing-rules", "BillingRules:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/price-indexes", "BillingRules:rates", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/price-indexes", "BillingRules:setRate", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/price-indexes/import", "BillingRules:import", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Documentos da unidade (ADR 0044): certidão, declaração, quitação e anuência. /verify antes de /{id}.
$route->get("/unit-documents", "UnitDocuments:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/unit-documents", "UnitDocuments:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/unit-documents/verify/{code}", "UnitDocuments:verify", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/unit-documents/{id}", "UnitDocuments:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/unit-documents/{id}/cancel", "UnitDocuments:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Leituras e consumo (ADR 0045): lançar leitura, listar com consumo e valor, cobrar a competência.
// Cadastro rápido de unidades e fração ideal/área em grade (ADR 0140). Fora de /units/{id}.
$route->post("/unit-batches/preview", "UnitBatches:preview", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/unit-batches", "UnitBatches:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/unit-measures", "UnitBatches:measures", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->put("/unit-measures", "UnitBatches:saveMeasures", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/unit-measures/distribute", "UnitBatches:distribute", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Saída de moradores da unidade (ADR 0206). Antes do laço dos cadastros.
$route->post("/units/move-out", "UnitMoveOut:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Conclusão de manutenções com a próxima agendada (ADR 0208). Antes do laço dos cadastros.
$route->post("/maintenances/complete", "MaintenanceCompletion:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobrança das taxas de reserva em lote pela cobrança extra (ADR 0212). Antes do laço dos cadastros.
$route->post("/reservations/bill-fees", "ReservationFeeBilling:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Ocorrências com o chamado do Help Desk encerrado (ADR 0213). Antes do laço dos cadastros.
$route->post("/occurrences/sync-tickets", "OccurrenceTicketSync:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote de malotes e avisos de recebimento (ADR 0216). Antes do laço dos cadastros.
$route->post("/document-transit/settle", "DocumentTransitSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Renovação de contratos em lote, com reajuste opcional (ADR 0217). Antes do laço dos cadastros (/contracts/{id}).
$route->post("/contracts/renew", "ContractRenewal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote das reservas que passaram sem aprovação (ADR 0221). Antes do laço dos cadastros (/reservations/{id}).
$route->post("/reservations/expire-pending", "ReservationExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reajuste salarial em lote dos funcionários (ADR 0223). Antes do laço dos cadastros (/employees/{id}).
$route->post("/employees/readjust-salaries", "EmployeeSalaryReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Desligamento em lote de funcionários (ADR 0225). Antes do laço dos cadastros (/employees/{id}).
$route->post("/employees/terminate", "EmployeeTermination:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reatribuição em lote dos chamados de um responsável para outro (ADR 0227). Antes do laço dos cadastros.
$route->post("/tickets/reassign", "TicketReassignment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Uso dos espaços comuns do condomínio no período, somente leitura (ADR 0228). Antes do laço dos cadastros.
$route->get("/reports/common-area-usage", "CommonAreaUsage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Bloqueio e liberação em lote das áreas comuns (ADR 0229). Antes do laço dos cadastros.
$route->post("/common-areas/booking-mode", "CommonAreaBookingMode:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Movimento da portaria do condomínio no período, somente leitura (ADR 0230). Antes do laço dos cadastros.
$route->get("/reports/gatehouse-traffic", "GatehouseTraffic:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote das visitas previstas vencidas em aberto (ADR 0231). Antes do laço dos cadastros.
$route->post("/visit-forecasts/expire", "VisitForecastExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Resumo dos achados e perdidos do condomínio, somente leitura (ADR 0232). Antes do laço dos cadastros.
$route->get("/reports/lost-found", "LostFoundSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Destino em lote dos achados e perdidos fora do prazo de guarda (ADR 0233). Antes do laço dos cadastros.
$route->post("/lost-found/dispose", "LostFoundDisposal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Situação das autorizações de visitantes numa data, somente leitura (ADR 0234). Antes do laço dos cadastros.
$route->get("/reports/visitor-authorizations", "VisitorAuthorizations:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Bloqueio em lote das autorizações de visitantes vencidas e ainda liberadas (ADR 0235). Antes do laço dos cadastros.
$route->post("/visitors/expire", "VisitorAuthorizationExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Resumo dos animais de estimação e dos veículos cadastrados, somente leitura (ADR 0236). Antes do laço dos cadastros.
$route->get("/reports/pets-vehicles", "PetsVehiclesSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote das inscrições vencidas da lista de espera (ADR 0237). Antes do laço dos cadastros.
$route->post("/waitlist/expire", "WaitlistExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Posição dos empenhos do condomínio, somente leitura (ADR 0238). Antes do laço dos cadastros.
$route->get("/reports/commitments", "CommitmentsPosition:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cancelamento em lote dos empenhos em aberto há muito tempo (ADR 0239). Antes do laço dos cadastros.
$route->post("/commitments/cancel-stale", "CommitmentCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Perfil de ocupação das unidades numa data, somente leitura (ADR 0240). Antes do laço dos cadastros.
$route->get("/reports/unit-occupancy", "UnitOccupancy:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote dos vínculos com o fim já passado, pelo cadastro (ADR 0241). Antes do laço dos cadastros.
$route->post("/residents/expire-ended", "ResidentLinkExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Vínculos perto do fim ou vencidos, por faixa de prazo, somente leitura (ADR 0242). Antes do laço dos cadastros.
$route->get("/reports/resident-link-endings", "ResidentLinkEndings:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote dos mandatos vencidos do síndico e do conselho, pelo cadastro (ADR 0243). Antes do laço dos cadastros.
$route->post("/board-members/expire-terms", "BoardTermExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobertura de seguros do condomínio numa data, somente leitura (ADR 0244). Antes do laço dos cadastros.
$route->get("/reports/insurance-coverage", "InsuranceCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote dos contratos com a vigência terminada, pelo cadastro (ADR 0245). Antes do laço dos cadastros.
$route->post("/contracts/close-ended", "ContractClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Carteira de contratos do condomínio numa data, somente leitura (ADR 0246). Antes do laço dos cadastros.
$route->get("/reports/contract-portfolio", "ContractPortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote dos agendamentos de mudança com a data já passada, pelo cadastro (ADR 0247). Antes do laço dos cadastros.
$route->post("/moves/settle-past", "MoveSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cumprimento do plano de manutenções num período, somente leitura (ADR 0248). Antes do laço dos cadastros.
$route->get("/reports/maintenance-compliance", "MaintenanceCompliance:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Vínculo em lote das cópias de cheques aos lançamentos pagos, pelo cadastro (ADR 0249). Antes do laço dos cadastros.
$route->post("/check-copies/link-entries", "CheckCopyMatching:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Carteira da cobrança judicial do condomínio, somente leitura (ADR 0250). Antes do laço dos cadastros.
$route->get("/reports/legal-cases", "LegalCasePortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote dos processos de cobrança com as contas quitadas (ADR 0251). Antes do laço dos cadastros.
$route->post("/legal-cases/close-settled", "LegalCaseSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobertura dos contatos úteis do condomínio por serviço, somente leitura (ADR 0252). Antes do laço dos cadastros.
$route->get("/reports/contact-coverage", "ContactCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Liquidação em lote dos empenhos com o lançamento já pago, tudo ou nada e com prévia (ADR 0253). Antes do laço dos cadastros.
$route->post("/commitments/settle-paid", "CommitmentSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Composição do síndico e do conselho numa data, somente leitura (ADR 0254). Antes do laço dos cadastros.
$route->get("/reports/board-composition", "BoardComposition:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote das ocorrências antigas sem chamado, tudo ou nada e com prévia (ADR 0255). Antes do laço dos cadastros.
$route->post("/occurrences/close-stale", "OccurrenceClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Idade das ocorrências em aberto por prioridade, somente leitura (ADR 0256). Antes do laço dos cadastros.
$route->get("/reports/occurrence-aging", "OccurrenceAging:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inativação em lote dos contatos úteis duplicados, tudo ou nada e com prévia (ADR 0257). Antes do laço dos cadastros (/useful-contacts/{id}).
$route->post("/useful-contacts/deactivate-duplicates", "ContactDeduplication:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobertura das tarifas de consumo do condomínio numa data, somente leitura (ADR 0258). Antes do laço dos cadastros.
$route->get("/reports/tariff-coverage", "TariffCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reajuste em lote das tarifas de consumo do condomínio, tudo ou nada e com prévia (ADR 0259). Antes do laço dos cadastros (/tariffs/{id}).
$route->post("/tariffs/readjust", "TariffReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Procura pelas áreas comuns na lista de espera, somente leitura (ADR 0260). Antes do laço dos cadastros.
$route->get("/reports/waitlist-demand", "WaitlistDemand:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cancelamento em lote das manutenções agendadas e esquecidas, tudo ou nada e com prévia (ADR 0261). Antes do laço dos cadastros.
$route->post("/maintenances/cancel-overdue", "MaintenanceCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobertura das alíquotas de retenção do condomínio, somente leitura (ADR 0262). Antes do laço dos cadastros.
$route->get("/reports/tax-coverage", "TaxCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cancelamento em lote das reservas de uma área comum interditada, tudo ou nada e com prévia (ADR 0263). Antes do laço dos cadastros (/reservations/{id}).
$route->post("/reservations/cancel-for-closure", "ReservationClosure:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Histórico das assembleias do condomínio num período, somente leitura (ADR 0264). Antes do laço dos cadastros.
$route->get("/reports/assembly-history", "AssemblyHistory:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Bloqueio em lote dos visitantes de unidades sem morador ativo, tudo ou nada e com prévia (ADR 0265). Antes do laço dos cadastros (/visitors/{id}).
$route->post("/visitors/block-unattached", "VisitorUnitBlock:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Visitantes pela situação da unidade (com morador, sem morador, inativa, sem vínculo), somente leitura (ADR 0266).
$route->get("/reports/visitor-units", "VisitorUnitSituation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inativação em lote dos veículos de unidades sem morador ativo, tudo ou nada e com prévia (ADR 0267). Antes do laço dos cadastros (/vehicles/{id}).
$route->post("/vehicles/deactivate-unattached", "VehicleUnitRelease:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Adesão ao portal do morador (entrou, convite pendente, vencido ou revogado, o que falta para convidar), somente leitura (ADR 0268).
$route->get("/reports/resident-portal", "ResidentPortalAdoption:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inativação em lote dos animais de unidades sem morador ativo, tudo ou nada e com prévia (ADR 0269). Antes do laço dos cadastros (/pets/{id}).
$route->post("/pets/deactivate-unattached", "PetUnitRelease:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Unidades vagas e o que ficou nelas (há quanto tempo, visitantes, veículos, animais e dívida vencida), somente leitura (ADR 0270).
$route->get("/reports/vacant-units", "VacantUnits:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Encerramento em lote dos lançamentos fixos que já passaram da última competência, tudo ou nada e com prévia (ADR 0271). Antes do laço dos cadastros (/recurring-entries/{id}).
$route->post("/recurring-entries/close-ended", "RecurringClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Carteira de lançamentos fixos numa competência (vigentes, terminando, já acabados, futuros, geração do mês), somente leitura (ADR 0272).
$route->get("/reports/recurring-portfolio", "RecurringPortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inativação em lote dos centros de custo parados, tudo ou nada e com prévia (ADR 0273). Antes do laço dos cadastros (/cost-centers/{id}).
$route->post("/cost-centers/deactivate-idle", "CostCenterDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Inventário do Disco Virtual (pastas, espaço, antivírus, idade, cópias entre pastas e lixeira), somente leitura (ADR 0274).
$route->get("/reports/virtual-disk", "VirtualDiskInventory:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cópias repetidas do Disco Virtual para a lixeira (exclusão lógica; o arquivo fica), tudo ou nada e com prévia (ADR 0275). Antes de /documents/{id}.
$route->post("/documents/trash-duplicates", "VirtualDiskDeduplication:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Vínculos dos arquivos do Disco Virtual (ligados e soltos por pasta, por cadastro, vínculos para a lixeira), somente leitura (ADR 0276).
$route->get("/reports/document-links", "VirtualDiskLinks:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Arquivos do Disco Virtual que ainda têm vínculo, de volta da lixeira, em lote, tudo ou nada e com prévia (ADR 0277).
$route->post("/documents/restore-linked", "VirtualDiskRestore:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Situação dos comunicados (publicados, agendados, cancelados, idade e envios com falha), somente leitura (ADR 0278).
$route->get("/reports/notice-board", "AnnouncementBoard:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/meter-readings", "MeterReadings:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/meter-readings", "MeterReadings:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/meter-readings/bill", "MeterReadings:bill", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Disco Virtual (ADR 0047): arquivos fora da pasta pública, download só com permissão e escopo.
$route->get("/documents", "VirtualDisk:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/documents", "VirtualDisk:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/documents/{id}", "VirtualDisk:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/documents/{id}/download", "VirtualDisk:download", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/documents/{id}/share", "VirtualDisk:share", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->delete("/documents/{id}", "VirtualDisk:destroy", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Anexos dos lançamentos (ADR 0174): nota fiscal, comprovante e recibo, guardados no Disco Virtual.
$route->get("/entries/{id}/attachments", "EntryAttachments:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/entries/{id}/attachments", "EntryAttachments:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/entries/{id}/attachments/{attachment}/download", "EntryAttachments:download", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->delete("/entries/{id}/attachments/{attachment}", "EntryAttachments:destroy", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Ocorrência abre chamado na fila do Help Desk (ADR 0034/0051). O cadastro é /occurrences, do motor.
$route->post("/occurrences/{id}/ticket", "Occurrences:ticket", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Dashboard e continuações do Operacional (ADR 0053): alertas, lista de espera e próxima manutenção.
$route->get("/dashboard", "OperationalDashboard:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Blocos que eram demonstração no dashboard (ADR 0157): financeiro, agenda e atividades recentes.
$route->get("/dashboard/finance", "OperationalDashboard:finance", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/dashboard/agenda", "OperationalDashboard:agenda", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/dashboard/activities", "OperationalDashboard:activities", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/reservations/{id}/call-waitlist", "OperationalDashboard:callWaitlist", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/maintenances/{id}/next", "OperationalDashboard:nextMaintenance", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Painéis do condomínio (AP-002) e da unidade (AP-003), ADR 0054.
$route->get("/panels/condominium/{id}", "Panels:condominium", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/panels/unit/{id}", "Panels:unit", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Fila de avisos do ERP (ADR 0055). /dispatch antes de /{id}.
$route->get("/notifications", "Notifications:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/notifications/dispatch", "Notifications:dispatch", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/notifications/{id}", "Notifications:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/notifications/{id}/retry", "Notifications:retry", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Lançamentos avulsos (ADR 0057): desconto, multa avulsa e cobrança extra, transferência entre contas.
$route->post("/entries/{id}/discount", "EntryShortcuts:discount", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/entries/{id}/adjustments", "EntryShortcuts:adjustments", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Baixa em lote (ADR 0191): várias contas em aberto pagas numa data e numa conta bancária, tudo ou nada.
$route->post("/entries/settle", "BatchSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Estorno em lote da baixa (ADR 0192): o caminho de volta, com motivo, respeitando recibo, conciliação e mês fechado.
$route->post("/entries/unsettle", "BatchReversal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Despesa parcelada (ADR 0195): N contas a pagar de uma compra, pelas regras do formulário, tudo ou nada.
$route->post("/entries/installments", "InstallmentPayables:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Reclassificação em lote (ADR 0196): conta e/ou centro de custo de vários lançamentos, pelas regras do formulário, com motivo.
$route->post("/entries/reclassify", "EntryReclassification:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Prorrogação de vencimento em lote (ADR 0197): contas em aberto, data fixa ou dias, pelas regras do formulário, com motivo.
$route->post("/entries/reschedule", "EntryReschedule:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/entries/cancel", "EntryCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/suppliers/deactivate", "SupplierDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/extra-charges", "EntryShortcuts:charges", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/extra-charges", "EntryShortcuts:storeCharge", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/extra-charges/{id}", "EntryShortcuts:charge", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/extra-charges/{id}/cancel", "EntryShortcuts:cancelCharge", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-transfers", "EntryShortcuts:transfers", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-transfers", "EntryShortcuts:storeTransfer", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-transfers/{id}", "EntryShortcuts:transfer", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-transfers/{id}/cancel", "EntryShortcuts:cancelTransfer", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Importações (ADR 0058): prévia, aplicação e conciliação do extrato OFX. /preview antes de /{id}.
$route->get("/imports", "Imports:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/imports/preview", "Imports:preview", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/imports/{id}", "Imports:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/imports/{id}/apply", "Imports:apply", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-statement-lines", "Imports:pendingLines", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-statement-lines/{id}/resolve", "Imports:resolveLine", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Nova conferência do extrato pendente pela regra da importação (ADR 0209).
$route->post("/bank-statement-lines/rematch", "StatementRematch:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Comunicação pela outbox (ADR 0059): comunicados, aviso de cobrança, agendados e endereços. /collection antes de /{id}.
$route->get("/announcements", "Announcements:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/announcements", "Announcements:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/announcements/collection", "Announcements:collection", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/announcements/{id}", "Announcements:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/announcements/{id}/cancel", "Announcements:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/assemblies", "Assemblies:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies", "Assemblies:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/assemblies/{id}", "Assemblies:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/convene", "Assemblies:convene", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/open", "Assemblies:open", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/attendance", "Assemblies:checkIn", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/items/{item}/start", "Assemblies:startItem", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/items/{item}/votes", "Assemblies:vote", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/items/{item}/close", "Assemblies:closeItem", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/close", "Assemblies:close", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/assemblies/{id}/cancel", "Assemblies:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/assemblies/{id}/attendance-report", "Assemblies:attendanceReport", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/assemblies/{id}/results", "Assemblies:results", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Conferência antes da assembleia (ADR 0211), somente leitura.
$route->get("/assemblies/{id}/readiness", "AssemblyReadiness:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/partner-agencies", "AccessAdmin:agencies", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-agencies", "AccessAdmin:storeAgency", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-agencies/{id}/deactivate", "AccessAdmin:deactivateAgency", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-agencies/{id}/users", "AccessAdmin:linkUser", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-users/{user}/unlink", "AccessAdmin:unlinkUser", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-users/{user}/condominiums", "AccessAdmin:grant", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/partner-users/{user}/condominiums/revoke", "AccessAdmin:revoke", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/partner/view", "AccessAdmin:partnerView", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/resident-invitations", "AccessAdmin:invitations", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/resident-invitations", "AccessAdmin:invite", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/resident-invitations/{id}/revoke", "AccessAdmin:revokeInvitation", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/resident-portal-permissions", "AccessAdmin:portal", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/resident-portal-permissions", "AccessAdmin:setPortal", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/communication-addresses", "Announcements:addresses", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Cobrança e multas (ADR 0080): multas pendentes, atendimento de cobrança e casos judiciais (só acompanhamento).
$route->get("/pending-fines", "Collections:fines", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/collection-desk", "Collections:desk", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/units/{id}/collection-contacts", "Collections:contacts", null, \Source\Support\Middleware\ApiAuthenticated::class);
// ADR 0162: busca e vínculo de pessoas (proprietário e inquilino)
$route->get("/people/search", "People:search", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/people/{id}/links", "People:links", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/collection-contacts", "Collections:storeContact", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/legal-cases", "Collections:cases", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/legal-cases", "Collections:storeCase", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/legal-cases/{id}", "Collections:showCase", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/legal-cases/{id}/events", "Collections:caseEvent", null, \Source\Support\Middleware\ApiAuthenticated::class);

// Cadastros do ERP (ADR 0014): um recurso por cadastro, mesmo RegistryService das telas.
foreach (\Source\Services\Erp\Registry\Definitions::all() as $registrySlug => $registryDefinition) {
    $registryPath = "/" . $registryDefinition["api"];
    $registryAuth = \Source\Support\Middleware\ApiAuthenticated::class;
    $route->get($registryPath, static fn () => (new \Source\Controllers\Api\V1\Registry())->index($registrySlug), null, $registryAuth);
    $route->post($registryPath, static fn () => (new \Source\Controllers\Api\V1\Registry())->store($registrySlug), null, $registryAuth);
    $route->get($registryPath . "/{id}", static fn (array $data) => (new \Source\Controllers\Api\V1\Registry())->show($registrySlug, $data), null, $registryAuth);
    $route->put($registryPath . "/{id}", static fn (array $data) => (new \Source\Controllers\Api\V1\Registry())->update($registrySlug, $data), null, $registryAuth);
    $route->patch($registryPath . "/{id}", static fn (array $data) => (new \Source\Controllers\Api\V1\Registry())->update($registrySlug, $data), null, $registryAuth);
    $route->delete($registryPath . "/{id}", static fn (array $data) => (new \Source\Controllers\Api\V1\Registry())->destroy($registrySlug, $data), null, $registryAuth);
}
unset($registrySlug, $registryDefinition, $registryPath, $registryAuth);

// Vistoria presencial (CN-001). Rotas fixas antes de /inspections/{id}.
$route->get("/inspection-templates", "Inspections:templates", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections", "Inspections:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections/schedule", "Inspections:schedule", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections", "Inspections:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections/{id}", "Inspections:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->put("/inspections/{id}/items/{item}", "Inspections:answer", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections/{id}/items/{item}/photos", "Inspections:photo", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections/{id}/complete", "Inspections:complete", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Chamados (ADR 0034): todo chamado cai na fila do Help Desk, com condomínio e unidade.
$route->get("/tickets", "Tickets:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/tickets", "Tickets:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/tickets/{id}", "Tickets:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/tickets/{id}/messages", "Tickets:messages", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/help/articles", "Help:articles", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/tickets/{id}/messages", "Tickets:reply", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Aplicativo do morador (ADR 0302): tudo preso ao vínculo morador-unidade e à matriz do portal.
$route->get("/resident/me", "ResidentApp:me", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/summary", "ResidentApp:summary", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/charges", "ResidentApp:charges", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/charges/{id}/pdf", "ResidentApp:chargePdf", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/accountability", "ResidentApp:accountability", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/announcements", "ResidentApp:announcements", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/common-areas", "ResidentApp:commonAreas", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/reservations", "ResidentApp:reservations", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/reservations", "ResidentApp:requestReservation", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/reservations/{id}/cancel", "ResidentApp:cancelReservation", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/waitlist", "ResidentApp:joinWaitlist", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/occurrences", "ResidentApp:occurrences", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/occurrences", "ResidentApp:reportOccurrence", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/deliveries", "ResidentApp:deliveries", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/visits", "ResidentApp:visits", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/visits", "ResidentApp:authorizeVisit", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/visits/{id}/cancel", "ResidentApp:cancelVisit", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/assemblies", "ResidentApp:assemblies", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/assemblies/{id}", "ResidentApp:assembly", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/documents", "ResidentApp:documents", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/documents/{id}/file", "ResidentApp:documentFile", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/pets-vehicles", "ResidentApp:petsVehicles", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->get("/resident/2fa", "ResidentApp:twoFactor", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/2fa/confirm", "ResidentApp:twoFactorConfirm", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/2fa/disable", "ResidentApp:twoFactorDisable", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->post("/resident/2fa/recovery", "ResidentApp:twoFactorRecovery", null, \Source\Support\Middleware\ResidentApiAuthenticated::class);
$route->namespace(null);

/**
 * TROCA DE AMBIENTE (ADR 0034): alternador da sidebar entre ERP, Studio e Help Desk, em
 * qualquer host. Entre hosts diferentes passa por um código de uso único.
 */
$route->group(null);
$route->namespace("Source\\Controllers\\Environments");
$route->get("/ambiente/{environment}", "Handoff:go");
$route->get("/ambiente/entrar/{environment}/{token}", "Handoff:enter");
$route->namespace(null);


/**
 * WEB, STUDIO, HELP DESK E APP — não existem no host próprio do ERP (erp.connect.lab):
 * cada ambiente fica isolado pelo hostname. No host do ERP só entram o 2FA do login
 * compartilhado e a recuperação de senha do núcleo.
 */
/**
 * VERIFICAÇÃO PÚBLICA DE DOCUMENTOS (ADRs 0098, 0099 e 0115): em qualquer host, sem login. Endereço fixo
 * /verificar-certidao-debitos; o /verificar dos documentos antigos redireciona para ele. O link impresso
 * nos documentos usa url(), que pode ser o host do ERP, e a página precisa responder mesmo com o site
 * desligado (CONF_ACCESS_SITE). O POST exige CSRF e tem limite por IP.
 */
$route->group(null);
$route->namespace("Source\\Controllers\\Web\\Connect");
$route->get("/verificar-certidao-debitos", "DocumentVerify:form");
$route->post("/verificar-certidao-debitos", "DocumentVerify:verify");
$route->get("/verificar", "DocumentVerify:legacy");
$route->post("/verificar", "DocumentVerify:verify");
// Link público da prestação de contas para os moradores (ADR 0301): sem login, em qualquer host, token no caminho.
$route->get("/prestacao/{token}", "AccountabilityLinkPublic:show");
$route->get("/prestacao/{token}/documento/{attachment}", "AccountabilityLinkPublic:file");
$route->namespace(null);

$moduleRole = Access::role(Auth::user());
$developerBypass = ($moduleRole->slug ?? null) === "developer";
if (erp_host()) {
    $route->namespace("Source\\Controllers\\Studio");
    $route->group("/studio");
    $route->get("/login/2fa", "TwoFactor:challenge");
    $route->post("/login/2fa", "TwoFactor:challenge");
    $route->get("/seguranca/2fa", "TwoFactor:manage");
    $route->post("/seguranca/2fa", "TwoFactor:manage");
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->group(null);
    $route->get("/forget", "Web:forget");
    $route->post("/forget", "Web:forget");
    $route->get("/forget/{code}", "Web:reset");
    $route->post("/reset", "Web:reset");
    $route->get("/convite/{token}", "ResidentInvite:open");
    $route->post("/convite/abrir", "ResidentInvite:claim");
    $route->get("/convite", "ResidentInvite:form");
    $route->post("/convite", "ResidentInvite:accept");
    $route->namespace(null);
} elseif (app_host()) {
    /**
     * Host próprio do aplicativo do morador (app.connect.lab, ADR 0302): só o app, o 2FA do login
     * compartilhado, a recuperação de senha e o aceite do convite. ERP, Studio e site não respondem aqui.
     */
    $route->namespace("Source\\Controllers\\Studio");
    $route->group("/studio");
    $route->get("/login/2fa", "TwoFactor:challenge");
    $route->post("/login/2fa", "TwoFactor:challenge");
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->group(null);
    $route->get("/forget", "Web:forget");
    $route->post("/forget", "Web:forget");
    $route->get("/forget/{code}", "Web:reset");
    $route->post("/reset", "Web:reset");
    $route->get("/convite/{token}", "ResidentInvite:open");
    $route->post("/convite/abrir", "ResidentInvite:claim");
    $route->get("/convite", "ResidentInvite:form");
    $route->post("/convite", "ResidentInvite:accept");
    $route->namespace(null);
    require moves_container_path('residents', CONF_VIEW_APP) . "/routes.php";
} elseif (studio_host()) {
    /**
     * Host próprio do Studio (studio.connect.lab, ADR 0026): só Studio, Help Desk e a
     * recuperação de senha do núcleo. A raiz leva ao painel; o site não responde aqui.
     */
    $route->namespace(null);
    $route->group(null);
    $route->get("/", static function (): void {
        header("Location: " . url("/studio"), true, 302);
    });
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->get("/forget", "Web:forget");
    $route->post("/forget", "Web:forget");
    $route->get("/forget/{code}", "Web:reset");
    $route->post("/reset", "Web:reset");
    $route->get("/convite/{token}", "ResidentInvite:open");
    $route->post("/convite/abrir", "ResidentInvite:claim");
    $route->get("/convite", "ResidentInvite:form");
    $route->post("/convite", "ResidentInvite:accept");
    require moves_container_path('studio', 'default') . "/routes.php";
    $helpDeskRoutes = moves_container_path('helpdesk', 'default') . "/routes.php";
    if (is_file($helpDeskRoutes)) {
        require $helpDeskRoutes;
    }
} else {

/**
 * WEB ROUTES
 */
if (CONF_ACCESS_SITE || $developerBypass) {
    require moves_container_path('web', CONF_VIEW_THEMES) . "/routes.php";
} else {
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->group("");
    $route->get("/", "Web:unavailable");
    $route->group("/ops");
    $route->get("/{errcode}", "Web:error");
}
$supportRoutes = moves_container_path('web', CONF_VIEW_SUPPORT) . "/routes.php";
if ((CONF_ACCESS_SUPPORT || $developerBypass) && is_file($supportRoutes)) {
    require $supportRoutes;
}

/**
 * STUDIO ROUTES
 */
require moves_container_path('studio', 'default') . "/routes.php";

/**
 * HELP DESK ROUTES
 */
$helpDeskRoutes = moves_container_path('helpdesk', 'default') . "/routes.php";
if (is_file($helpDeskRoutes)) {
    require $helpDeskRoutes;
}

/**
 * APP DO MORADOR (ADR 0302): no host único fica em /app.
 */
$appRoutes = moves_container_path('residents', CONF_VIEW_APP) . "/routes.php";
if (is_file($appRoutes)) {
    require $appRoutes;
}
}

/**
 * ERP ROUTES
 */
// A1: default.php é a moldura do tema; as rotas ficam em routes.php.
$erpRoutes = moves_container_path('erp', CONF_VIEW_ERP) . "/routes.php";
if (!app_host() && is_file($erpRoutes)) {
    require $erpRoutes;
}

/**
 * CONDOMÍNIO EM CONTEXTO (ADR 0030): tira o slug do condomínio da URL do ERP antes do
 * dispatch e leva URLs antigas, /todos e filtros ?condominio= para a URL canônica.
 */
if (erp_host() || preg_match("~/erp(?:/|$)~", (string)parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH))) {
    $erpContextRedirect = \Source\Support\Erp\CondominiumContext::bootRequest($route->firstSegments());
    if ($erpContextRedirect !== null) {
        header("Location: " . $erpContextRedirect, true, 302);
        ob_end_flush();
        return;
    }
}

/**
 * ROUTE
 */
$route->dispatch();

/**
 * ERROR REDIRECT
 */
if ($route->error() && erp_host()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota do ERP finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => 'resolved'], 'http');
    (new \Source\Controllers\Erp\Connect\Panel())->routeError((int)$route->error());
} elseif ($route->error() && app_host()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota do app do morador finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => 'resolved'], 'http');
    (new \Source\Controllers\App\Resident\AppScreens())->error(['errcode' => (int)$route->error()]);
} elseif ($route->error()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => (int)$route->error() >= 500 ? 'open' : 'resolved'], 'http');
    $requestPath = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
    $isStudio = (bool)preg_match("~/studio(?:/|$)~", $requestPath);
    $isHelpDesk = (bool)preg_match("~/helpdesk(?:/|$)~", $requestPath);
    $isSystemArea = (bool)preg_match("~/(?:studio|helpdesk|app|erp|suporte)(?:/|$)~", $requestPath);
    $publicError = !CONF_ACCESS_SITE && !$developerBypass && !$isSystemArea ? "indisponivel" : $route->error();
    // No host do Studio não existe /ops do site: todo erro vai para a página de erro do painel.
    $route->redirect(($isHelpDesk ? "/helpdesk/ops/" : ($isStudio || studio_host() ? "/studio/ops/" : "/ops/")) . $publicError);
}

ob_end_flush();
