<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use PDO;
use Source\Models\Auth;
use Source\Models\User;
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

    private int $alpha;
    private int $beta;
    private int $betaUnit;
    private int $betaEntry;
    private int $betaAccount;

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

    private function call(User $user, string $controller, string $action, int $id): int
    {
        Auth::actingAs($user);
        \Source\Support\Access::clear();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $class = 'Source\\Controllers\\Api\\V1\\' . $controller;
        ob_start();
        try {
            (new $class())->$action(['id' => (string)$id]);
        } catch (\Throwable $error) {
            ob_end_clean();
            return 500;
        }
        ob_end_clean();
        return (int)http_response_code();
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

    /** Linha mínima no Beta: colunas obrigatórias sem padrão recebem valores neutros. @param array<string, mixed> $values */
    private function row(string $table, array $values): int
    {
        $cols = $this->pdo->prepare("SELECT column_name, data_type, column_type, character_maximum_length, is_nullable, column_default, extra
            FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
        $cols->execute([$table]);
        $row = [];
        foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $name = (string)$c['column_name'];
            if (str_contains((string)$c['extra'], 'auto_increment') || str_contains((string)$c['extra'], 'GENERATED')) {
                continue;
            }
            if ($name === 'condominium_id') {
                $row[$name] = $this->beta;
            } elseif ($name === 'unit_id' && isset($this->betaUnit)) {
                $row[$name] = $this->betaUnit;
            } elseif ($name === 'entry_id' && isset($this->betaEntry)) {
                $row[$name] = $this->betaEntry;
            } elseif (str_ends_with($name, 'bank_account_id') && isset($this->betaAccount)) {
                $row[$name] = $this->betaAccount;
            } elseif ($c['is_nullable'] === 'NO' && $c['column_default'] === null) {
                $row[$name] = match ((string)$c['data_type']) {
                    'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double' => 1,
                    'date' => date('Y-m-d'),
                    'datetime', 'timestamp' => date('Y-m-d H:i:s'),
                    'time' => '10:00:00',
                    'year' => (int)date('Y'),
                    'enum' => preg_match("/^enum\\('([^']*)'/", (string)$c['column_type'], $m) ? $m[1] : '',
                    'json', 'longtext' => '{}',
                    default => $name === 'competence' ? date('Y-m') : substr($name . bin2hex(random_bytes(3)), 0, max(1, min(20, (int)($c['character_maximum_length'] ?? 20)))),
                };
            }
        }
        $row = $values + $row;
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $names = array_keys($row);
        $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($row));
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $id;
    }
}
