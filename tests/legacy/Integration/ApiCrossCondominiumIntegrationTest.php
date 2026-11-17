<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use MovesOSTests\Fakes\ScopeTestInput;
use Source\Services\CondominiumScope;

/**
 * Revisão de IDOR da API (ADR 0340): para cada GET /api/v1/.../{id} do ERP, um registro do condomínio Beta é pedido por
 * quem tem todas as permissões do ERP mas só enxerga o Alpha. A resposta precisa ser recusa (403/404), nunca o registro.
 * Controle positivo: o mesmo pedido de quem enxerga todos os condomínios não pode ser 403/404, senão o teste não provaria
 * nada (o registro de fixture não seria encontrado por ninguém).
 *
 * As linhas são montadas a partir do information_schema (colunas obrigatórias preenchidas com valores neutros), com
 * condominium_id = Beta e as chaves de unidade, lançamento e conta bancária apontando para registros reais do Beta.
 */
final class ApiCrossCondominiumIntegrationTest extends TestCase
{
    use ScopeFixtures;

    /** rota => [controller, ação, tabela]; a tabela recebe a linha do Beta. */
    private const ROUTES = [
        'GET /condominiums/{id}' => ['Condominiums', 'show', null],
        'GET /panels/condominium/{id}' => ['Panels', 'condominium', null],
        'GET /condominiums/{id}/nfse-requests' => ['ManagementFees', 'requests', null],
        'GET /units/{id}/occupants' => ['UnitOccupants', 'show', 'erp_units'],
        'GET /panels/unit/{id}' => ['Panels', 'unit', 'erp_units'],
        'GET /units/{id}/collection-contacts' => ['Collections', 'contacts', 'erp_units'],
        'GET /invoices/{id}' => ['Invoices', 'show', 'erp_invoices'],
        'GET /bank-charges/{id}' => ['Banking', 'show', 'erp_bank_charges'],
        'GET /accountability-publications/{id}' => ['AccountabilityPublications', 'show', 'erp_accountability_publications'],
        'GET /patrimonial-imports/{id}' => ['PatrimonialImports', 'show', 'erp_patrimonial_imports'],
        'GET /agreements/{id}' => ['Agreements', 'show', 'erp_agreements'],
        'GET /unit-documents/{id}' => ['UnitDocuments', 'show', 'erp_unit_documents'],
        'GET /documents/{id}' => ['VirtualDisk', 'show', 'erp_documents'],
        'GET /entries/{id}/attachments' => ['EntryAttachments', 'index', 'erp_entries'],
        'GET /entries/{id}/adjustments' => ['EntryShortcuts', 'adjustments', 'erp_entries'],
        'GET /extra-charges/{id}' => ['EntryShortcuts', 'charge', 'erp_extra_charges'],
        'GET /bank-transfers/{id}' => ['EntryShortcuts', 'transfer', 'erp_bank_transfers'],
        'GET /imports/{id}' => ['Imports', 'show', 'erp_imports'],
        'GET /announcements/{id}' => ['Announcements', 'show', 'erp_announcements'],
        'GET /assemblies/{id}' => ['Assemblies', 'show', 'erp_assemblies'],
        'GET /assemblies/{id}/attendance-report' => ['Assemblies', 'attendanceReport', 'erp_assemblies'],
        'GET /assemblies/{id}/results' => ['Assemblies', 'results', 'erp_assemblies'],
        'GET /assemblies/{id}/readiness' => ['AssemblyReadiness', 'show', 'erp_assemblies'],
        'GET /legal-cases/{id}' => ['Collections', 'showCase', 'erp_legal_cases'],
        'GET /inspections/{id}' => ['Inspections', 'show', 'inspections'],
        'GET /tickets/{id}' => ['Tickets', 'show', 'studio_support_tickets'],
        'GET /tickets/{id}/messages' => ['Tickets', 'messages', 'studio_support_tickets'],
    ];


    /**
     * Ações que alteram (ADR 0341): o corpo vai pelo php://input do teste (ScopeTestInput). Com o corpo genérico de BODY todas chegam ao carregamento do registro: quem só vê o Alpha recebe 403/404 e quem vê
     * todos não (controle positivo, que pode alterar a linha do Beta; o banco de teste é zerado a cada teste).
     * rota => [método HTTP, controller, ação, tabela, tabela do segundo id ou null].
     */
    private const WRITE_ROUTES = [
        'POST /invoices/{id}/issue' => ['POST', 'Invoices', 'issue', 'erp_invoices', null],
        'POST /invoices/{id}/cancel' => ['POST', 'Invoices', 'cancel', 'erp_invoices', null],
        'PATCH /invoices/{id}/items/{item}' => ['PATCH', 'Invoices', 'updateItem', 'erp_invoices', 'erp_invoice_items'],
        'POST /invoices/{id}/bank-charges' => ['POST', 'Banking', 'issueInvoice', 'erp_invoices', null],
        'POST /bank-charges/{id}/refresh' => ['POST', 'Banking', 'refresh', 'erp_bank_charges', null],
        'POST /bank-charges/{id}/cancel' => ['POST', 'Banking', 'cancel', 'erp_bank_charges', null],
        'POST /bank-charges/{id}/simulate-payment' => ['POST', 'Banking', 'simulatePayment', 'erp_bank_charges', null],
        'POST /bank-events/{id}/resolve' => ['POST', 'Banking', 'resolve', 'erp_bank_events', null],
        'POST /accountability-publications/{id}/withdraw' => ['POST', 'AccountabilityPublications', 'withdraw', 'erp_accountability_publications', null],
        'POST /accountability-links/{id}/send' => ['POST', 'AccountabilityLinks', 'send', 'erp_accountability_links', null],
        'POST /accountability-links/{id}/revoke' => ['POST', 'AccountabilityLinks', 'revoke', 'erp_accountability_links', null],
        'DELETE /local-holidays/{id}' => ['DELETE', 'LocalHolidays', 'destroy', 'erp_local_holidays', null],
        'POST /patrimonial-entries/{id}/reverse' => ['POST', 'PatrimonialEntries', 'reverse', 'erp_patrimonial_entries', null],
        'POST /investments/{id}/link' => ['POST', 'Investments', 'link', 'erp_bank_accounts', null],
        'POST /investments/{id}/apply' => ['POST', 'Investments', 'apply', 'erp_bank_accounts', null],
        'POST /investments/{id}/redeem' => ['POST', 'Investments', 'redeem', 'erp_bank_accounts', null],
        'POST /patrimonial-imports/{id}/apply' => ['POST', 'PatrimonialImports', 'apply', 'erp_patrimonial_imports', null],
        'POST /agreements/{id}/cancel' => ['POST', 'Agreements', 'cancel', 'erp_agreements', null],
        'POST /agreements/{id}/breach' => ['POST', 'Agreements', 'breach', 'erp_agreements', null],
        'POST /unit-documents/{id}/cancel' => ['POST', 'UnitDocuments', 'cancel', 'erp_unit_documents', null],
        'POST /units/{id}/occupant-change' => ['POST', 'UnitOccupants', 'change', 'erp_units', null],
        'POST /condominiums/{id}/management-fee' => ['POST', 'ManagementFees', 'save', null, null],
        'POST /condominiums/{id}/nfse-requests' => ['POST', 'ManagementFees', 'request', null, null],
        'POST /nfse-requests/{id}/cancel' => ['POST', 'ManagementFees', 'cancel', 'erp_nfse_requests', null],
        'POST /documents/{id}/share' => ['POST', 'VirtualDisk', 'share', 'erp_documents', null],
        'DELETE /documents/{id}' => ['DELETE', 'VirtualDisk', 'destroy', 'erp_documents', null],
        'GET /documents/{id}/download' => ['GET', 'VirtualDisk', 'download', 'erp_documents', null],
        'POST /entries/{id}/attachments' => ['POST', 'EntryAttachments', 'store', 'erp_entries', null],
        'GET /entries/{id}/attachments/{attachment}/download' => ['GET', 'EntryAttachments', 'download', 'erp_entries', 'erp_entry_attachments'],
        'DELETE /entries/{id}/attachments/{attachment}' => ['DELETE', 'EntryAttachments', 'destroy', 'erp_entries', 'erp_entry_attachments'],
        'POST /occurrences/{id}/ticket' => ['POST', 'Occurrences', 'ticket', 'erp_occurrences', null],
        'POST /reservations/{id}/call-waitlist' => ['POST', 'OperationalDashboard', 'callWaitlist', 'erp_reservations', null],
        'POST /maintenances/{id}/next' => ['POST', 'OperationalDashboard', 'nextMaintenance', 'erp_maintenances', null],
        'POST /entries/{id}/discount' => ['POST', 'EntryShortcuts', 'discount', 'erp_entries', null],
        'POST /extra-charges/{id}/cancel' => ['POST', 'EntryShortcuts', 'cancelCharge', 'erp_extra_charges', null],
        'POST /bank-transfers/{id}/cancel' => ['POST', 'EntryShortcuts', 'cancelTransfer', 'erp_bank_transfers', null],
        'POST /imports/{id}/apply' => ['POST', 'Imports', 'apply', 'erp_imports', null],
        'POST /bank-statement-lines/{id}/resolve' => ['POST', 'Imports', 'resolveLine', 'erp_bank_statement_lines', null],
        'POST /announcements/{id}/cancel' => ['POST', 'Announcements', 'cancel', 'erp_announcements', null],
        'POST /assemblies/{id}/convene' => ['POST', 'Assemblies', 'convene', 'erp_assemblies', null],
        'POST /assemblies/{id}/open' => ['POST', 'Assemblies', 'open', 'erp_assemblies', null],
        'POST /assemblies/{id}/attendance' => ['POST', 'Assemblies', 'checkIn', 'erp_assemblies', null],
        'POST /assemblies/{id}/items/{item}/start' => ['POST', 'Assemblies', 'startItem', 'erp_assemblies', 'erp_assembly_items'],
        'POST /assemblies/{id}/items/{item}/votes' => ['POST', 'Assemblies', 'vote', 'erp_assemblies', 'erp_assembly_items'],
        'POST /assemblies/{id}/items/{item}/close' => ['POST', 'Assemblies', 'closeItem', 'erp_assemblies', 'erp_assembly_items'],
        'POST /assemblies/{id}/close' => ['POST', 'Assemblies', 'close', 'erp_assemblies', null],
        'POST /assemblies/{id}/cancel' => ['POST', 'Assemblies', 'cancel', 'erp_assemblies', null],
        'POST /people/{id}/links' => ['POST', 'People', 'links', 'erp_residents', null],
        'POST /legal-cases/{id}/events' => ['POST', 'Collections', 'caseEvent', 'erp_legal_cases', null],
        'PUT /inspections/{id}/items/{item}' => ['PUT', 'Inspections', 'answer', 'inspections', 'inspection_answers'],
        'POST /inspections/{id}/complete' => ['POST', 'Inspections', 'complete', 'inspections', null],
        'POST /tickets/{id}/messages' => ['POST', 'Tickets', 'reply', 'studio_support_tickets', null],
    ];

    /** Sem controle positivo: a recusa do Beta vem da trava de escopo lida no código, mas a fixture genérica não basta para o sucesso. */
    private const NO_CONTROL = [
        'DELETE /local-holidays/{id}' => 'feriado é por cidade; a trava é o condominium_id da consulta (LocalHolidays::condominium)',
        'POST /units/{id}/occupant-change' => 'precisa de uma pessoa real; a trava é UnitOccupantChange::unit($user, ...)',
        'GET /documents/{id}/download' => 'precisa do arquivo no disco; a trava é a mesma do GET /documents/{id}',
        'GET /entries/{id}/attachments/{attachment}/download' => 'precisa do arquivo no disco; a trava é a mesma do POST de anexos',
    ];

    /** Valores fixos de algumas tabelas para a linha ser encontrada por quem vê tudo. */
    private const FIXTURE = ['erp_bank_events' => ['outcome' => 'divergence', 'resolved_at' => null]];

    /** Corpo genérico das ações que alteram: passa da leitura do JSON e chega ao carregamento do registro. */
    private const BODY = '{"reason":"Teste de escopo entre condomínios","note":"Teste de escopo","notes":"Teste","resolution":"ok","message":"Teste de escopo",'
        . '"date":"2026-11-17","amount_cents":100,"discount_cents":1,"value":"1,00","status":"active","answer":"ok","links":[],"area":"administrativo","shared":true,"role":"tenant","invoices":"none","unit_ids":[%d],"exit_on":"2026-11-16","entry_on":"2026-11-17","person_id":1}';

    private int $alpha;
    private int $beta;
    private int $betaUnit;
    private int $betaEntry;
    private int $betaAccount;
    private int $betaInvoiceType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->betaAccount = $this->row('erp_bank_accounts', ['deleted_at' => null]);
        $this->betaInvoiceType = $this->row('erp_invoice_types', []);
        $this->betaUnit = $this->row('erp_units', []);
        $this->betaEntry = $this->row('erp_entries', ['nature' => 'receita', 'deleted_at' => null]);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        http_response_code(200);
        parent::tearDown();
    }

    public function testOutOfScopeRecordsAreRefusedOnEveryIdRoute(): void
    {
        $scoped = $this->user(false);
        $global = $this->user(true);
        $ids = [];
        foreach (self::ROUTES as $route => [, , $table]) {
            $ids[$route] = match ($table) {
                null => $this->beta,
                'erp_units' => $this->betaUnit,
                'erp_entries' => $this->betaEntry,
                default => $ids[$table] ??= $this->row($table, []),
            };
        }
        $leaks = [];
        $unproven = [];
        foreach (self::ROUTES as $route => [$controller, $action]) {
            $denied = $this->call($scoped, $controller, $action, $ids[$route]);
            if (!in_array($denied, [403, 404], true)) {
                $leaks[] = $route . ' => ' . $denied;
            }
            $control = $this->call($global, $controller, $action, $ids[$route]);
            if (in_array($control, [401, 403, 404], true)) {
                $unproven[] = $route . ' => ' . $control;
            }
        }
        self::assertSame([], $leaks, 'Registro de outro condomínio entregue pela API.');
        self::assertSame([], $unproven, 'Controle positivo falhou: a fixture não prova a recusa nessas rotas.');
    }

    public function testOutOfScopeRecordsCannotBeChanged(): void
    {
        $scoped = $this->user(false);
        $global = $this->user(true);
        $unproven = [];
        $made = ['erp_units' => $this->betaUnit, 'erp_entries' => $this->betaEntry, 'erp_bank_accounts' => $this->betaAccount];
        $leaks = [];
        foreach (self::WRITE_ROUTES as $route => [$method, $controller, $action, $table, $second]) {
            $id = $table === null ? $this->beta : ($made[$table] ??= $this->row($table, self::FIXTURE[$table] ?? []));
            $data = ['id' => (string)$id];
            if ($second !== null) {
                $data['item'] = $data['attachment'] = (string)($made[$second] ??= $this->row($second, []));
            }
            $status = $this->call($scoped, $controller, $action, $data, $method);
            if (!in_array($status, [403, 404], true)) {
                $leaks[] = $route . ' => ' . $status;
            }
            $control = isset(self::NO_CONTROL[$route]) ? 200 : $this->call($global, $controller, $action, $data, $method);
            if (in_array($control, [401, 403, 404], true)) {
                $unproven[] = $route . ' => ' . $control;
            }
        }
        self::assertSame([], $leaks, 'Ação sobre registro de outro condomínio não foi recusada.');
        self::assertSame([], $unproven, 'Controle positivo falhou: a fixture não prova a recusa nessas rotas.');
    }

    /**
     * Imobiliária parceira (ADR 0343): papel erp_partner (só erp.access e erp.partner.view) com acesso concedido ao próprio
     * Beta. Mesmo dentro do condomínio concedido, nenhuma rota de leitura com id nem ação que altera pode responder 2xx:
     * a consulta do parceiro é só a tela própria, com dados mascarados (ADR 0090).
     */
    public function testPartnerAgencyReachesNoRecordEvenInGrantedCondominium(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO access_roles (name, slug, level, description) VALUES ('Imobiliária parceira', 'erp_partner', 1, 'teste')");
        $this->pdo->exec("INSERT IGNORE INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p
            WHERE r.slug = 'erp_partner' AND p.slug IN ('erp.access', 'erp.partner.view')");
        $partnerId = $this->createUser(['level' => 1]);
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'erp_partner'")->execute([$partnerId]);
        (new CondominiumScope($this->pdo))->grant($partnerId, $this->beta);
        $this->pdo->exec("UPDATE settings SET access_erp = 1");
        $partner = (new User())->findById($partnerId);

        $made = ['erp_units' => $this->betaUnit, 'erp_entries' => $this->betaEntry, 'erp_bank_accounts' => $this->betaAccount];
        $calls = [];
        foreach (self::ROUTES as $route => [$controller, $action, $table]) {
            $calls[$route] = ['GET', $controller, $action, ['id' => (string)($table === null ? $this->beta : ($made[$table] ??= $this->row($table, self::FIXTURE[$table] ?? [])))]];
        }
        foreach (self::WRITE_ROUTES as $route => [$method, $controller, $action, $table, $second]) {
            $data = ['id' => (string)($table === null ? $this->beta : ($made[$table] ??= $this->row($table, self::FIXTURE[$table] ?? [])))];
            if ($second !== null) {
                $data['item'] = $data['attachment'] = (string)($made[$second] ??= $this->row($second, []));
            }
            $calls[$route] = [$method, $controller, $action, $data];
        }
        $reached = [];
        foreach ($calls as $route => [$method, $controller, $action, $data]) {
            $status = $this->call($partner, $controller, $action, $data, $method);
            if (!in_array($status, [401, 403, 404], true)) {
                $reached[] = $route . ' => ' . $status;
            }
        }
        self::assertCount(count(self::ROUTES) + count(self::WRITE_ROUTES), $calls);
        self::assertSame([], $reached, 'A imobiliária parceira chegou a um registro fora da consulta própria.');

        // Chamado que a própria imobiliária abriu continua visível para ela; o do condomínio, não.
        $own = $this->row('studio_support_tickets', ['created_by' => $partnerId]);
        $tickets = new \Source\Services\Support\TicketService($this->pdo);
        self::assertNotNull($tickets->findFor($partner, $own));
        self::assertNull($tickets->findFor($partner, (int)$made['studio_support_tickets']));
        self::assertSame([$own], array_map(static fn (array $t): int => (int)$t['id'], $tickets->listFor($partner)));
    }

    /** @param int|array<string, string> $id */
    private function call(User $user, string $controller, string $action, int|array $id, string $method = 'GET'): int
    {
        Auth::actingAs($user);
        \Source\Support\Access::clear();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $method === 'GET' ? [] : ['kind' => 'outro', 'title' => 'Teste'];
        $_GET = $method === 'GET' ? [] : ['condominium_id' => (string)$this->beta];
        $class = 'Source\\Controllers\\Api\\V1\\' . $controller;
        $body = $method === 'GET' ? null : sprintf(self::BODY, $this->betaUnit);
        if ($body !== null) {
            ScopeTestInput::$body = $body;
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', ScopeTestInput::class);
        }
        ob_start();
        try {
            (new $class())->$action(is_array($id) ? $id : ['id' => (string)$id]);
            $status = (int)http_response_code();
        } catch (\Throwable $error) {
            $status = 500;
        } finally {
            ob_end_clean();
            if ($body !== null) {
                stream_wrapper_restore('php');
            }
        }
        return $status;
    }

    private function user(bool $global): User
    {
        $id = $this->createUser(['level' => 5]);
        $slug = 'idor_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $sql = 'INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions';
        $this->pdo->prepare($global ? $sql : $sql . ' WHERE slug <> ?')->execute($global ? [$role] : [$role, CondominiumScope::ALL_PERMISSION]);
        if (!$global) {
            (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        }
        $this->pdo->exec("UPDATE settings SET access_erp = 1");
        return (new User())->findById($id);
    }

    /** Linha mínima no Beta (ScopeFixtures). @param array<string, mixed> $values */
    private function row(string $table, array $values): int
    {
        return $this->scopeRow($table, $values, array_filter([
            'condominium_id' => $this->beta, 'unit_id' => $this->betaUnit ?? null, 'entry_id' => $this->betaEntry ?? null,
            'invoice_type_id' => $this->betaInvoiceType ?? null, '*bank_account_id' => $this->betaAccount ?? null,
        ]));
    }
}
