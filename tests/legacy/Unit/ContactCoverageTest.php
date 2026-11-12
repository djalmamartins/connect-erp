<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContactCoverage;

/**
 * ADR 0252: cobertura dos contatos úteis. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura do
 * resumo por serviço (próprios, compartilhados, inativos, com e-mail, cobertos e essenciais em falta; total = soma das
 * linhas), a consulta com os compartilhados e sem dado do contato, a validação, o escopo e o contrato; os contatos gravados
 * pelo cadastro são conferidos no MariaDB (Integration\ContactCoverageTest).
 */
final class ContactCoverageTest extends TestCase
{
    private PDO $pdo;
    private ContactCoverage $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_useful_contacts (id INTEGER PRIMARY KEY, condominium_id INTEGER, service TEXT, name TEXT, phone TEXT, email TEXT, notes TEXT, status TEXT,
                deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new ContactCoverage($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $c = static fn (string $service, bool $shared, string $status = 'active', bool $email = false): array => ['service' => $service, 'shared' => $shared,
            'status' => $status, 'has_email' => $email];
        $summary = ContactCoverage::summarize([$c('emergencia', true), $c('encanador', false, 'active', true), $c('encanador', false, 'inactive'),
            $c('eletricista', false, 'inactive'), $c('chaveiro', true, 'inactive'), $c('xyz', false)]);
        self::assertSame(['emergencia', 'encanador', 'eletricista', 'chaveiro', 'dedetizacao', 'gas', 'concessionaria', 'outro'],
            array_column($summary['rows'], 'service'), 'Os serviços vêm do cadastro.');
        self::assertSame([0, 1, 0, 0, 0, 0, 0, 1], array_column($summary['rows'], 'own'));
        self::assertSame([1, 0, 0, 0, 0, 0, 0, 0], array_column($summary['rows'], 'shared'), 'Compartilhado inativo não conta.');
        self::assertSame([0, 1, 1, 0, 0, 0, 0, 0], array_column($summary['rows'], 'inactive_own'));
        self::assertSame([true, true, false, false, false, false, false, true], array_column($summary['rows'], 'covered'), 'Inativo não cobre; desconhecido vira Outro.');
        self::assertSame(['own' => 2, 'shared' => 1, 'inactive_own' => 2, 'with_email' => 1], $summary['totals']);
        foreach (array_keys($summary['totals']) as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([3, 8, ['eletricista', 'gas']], [$summary['covered_services'], $summary['services'], $summary['missing_essential']]);
        self::assertSame(ContactCoverage::ESSENTIAL, ContactCoverage::summarize([])['missing_essential']);
    }

    public function testBuildWithSharedContactsAndWithoutContactData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_useful_contacts (condominium_id, service, name, phone, email, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'eletricista', 'Elétrica Fulano', '16994007100', 'fulano@exemplo.com', 'Chave na portaria', 'active', null]);
        $insert->execute([null, 'emergencia', 'Bombeiros', '193', null, null, 'active', null]);
        $insert->execute([3, 'gas', 'Gás de fora', '16990000000', null, null, 'active', null]);
        $insert->execute([1, 'gas', 'Gás excluído', '16990000001', null, null, 'active', '2026-01-01 00:00:00']);
        $report = $this->service->build($this->user(), '1');
        self::assertSame([1, ['own' => 1, 'shared' => 1, 'inactive_own' => 0, 'with_email' => 1], 2, ['encanador', 'gas']], [$report['condominium_id'], $report['totals'],
            $report['covered_services'], $report['missing_essential']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', '16994007100', 'exemplo.com', 'Bombeiros', 'portaria', 'phone', 'name'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do contato: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, 'x', 0, '1.5'] as $c) {
            try {
                $this->service->build($this->user(), $c);
                self::fail('Entrada inválida passou: ' . json_encode($c));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ContactCoverage.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('contacts')", 'deleted_at IS NULL AND (condominium_id = ? OR condominium_id IS NULL)'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', ', name', 'phone', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do contato: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ContactCoverage.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.contacts.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/contact-coverage", "ContactCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
