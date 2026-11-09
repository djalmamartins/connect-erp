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
$route->get("/me", "Me:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums", "Condominiums:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums/{id}", "Condominiums:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums/{id}/onboarding", "CondominiumOnboarding:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding", "CondominiumOnboarding:start", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding/items", "CondominiumOnboarding:add", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/condominiums/{id}/onboarding/items/{item}", "CondominiumOnboarding:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
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
// Fechamento mensal (ADR 0031): resumo, fechar e reabrir a competência de um condomínio.
$route->get("/closings", "MonthClosings:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/closings", "MonthClosings:close", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/closings/reopen", "MonthClosings:reopen", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Faturas (ADR 0033): preparar, ajustar item, emitir (gera contas a receber) e cancelar.
$route->get("/invoices", "Invoices:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices", "Invoices:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/invoices/{id}", "Invoices:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/invoices/{id}/items/{item}", "Invoices:updateItem", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices/{id}/issue", "Invoices:issue", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/invoices/{id}/cancel", "Invoices:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Boletos, retorno e fila de divergências (ADR 0035), só com o adaptador sandbox.
$route->get("/bank-charges", "Banking:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges", "Banking:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-charges/{id}", "Banking:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges/{id}/refresh", "Banking:refresh", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-charges/{id}/cancel", "Banking:cancel", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-returns", "Banking:returns", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-returns", "Banking:importReturn", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/bank-events/divergences", "Banking:divergences", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/bank-events/{id}/resolve", "Banking:resolve", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios financeiros (ADR 0037): balancete de verificação e prestação de contas com conciliação.
$route->get("/reports/trial-balance", "FinancialReports:trialBalance", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/reports/statement", "FinancialReports:statement", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Relatórios de cobrança (ADR 0081): acordos, taxas de acordos, inadimplentes e recebimentos; ?format=csv.
$route->get("/reports/agreements", "CollectionReports:agreements", null, \Source\Support\Middleware\ApiAuthenticated::class);
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
$route->get("/meter-readings", "MeterReadings:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/meter-readings", "MeterReadings:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/meter-readings/bill", "MeterReadings:bill", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Disco Virtual (ADR 0047): arquivos fora da pasta pública, download só com permissão e escopo.
$route->get("/documents", "VirtualDisk:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/documents", "VirtualDisk:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/documents/{id}", "VirtualDisk:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/documents/{id}/download", "VirtualDisk:download", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->delete("/documents/{id}", "VirtualDisk:destroy", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Ocorrência abre chamado na fila do Help Desk (ADR 0034/0051). O cadastro é /occurrences, do motor.
$route->post("/occurrences/{id}/ticket", "Occurrences:ticket", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Dashboard e continuações do Operacional (ADR 0053): alertas, lista de espera e próxima manutenção.
$route->get("/dashboard", "OperationalDashboard:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
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
 * APP
 */
$appRoutes = moves_container_path('residents', CONF_VIEW_APP) . "/default.php";
if (is_file($appRoutes)) {
    require $appRoutes;
}
}

/**
 * ERP ROUTES
 */
// A1: default.php é a moldura do tema; as rotas ficam em routes.php.
$erpRoutes = moves_container_path('erp', CONF_VIEW_ERP) . "/routes.php";
if (is_file($erpRoutes)) {
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
