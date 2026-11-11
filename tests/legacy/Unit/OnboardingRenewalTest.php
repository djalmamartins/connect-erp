<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\OnboardingRenewal;

/**
 * ADR 0219: renovação em lote do checklist de chegada. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO
 * de teste tira o FOR UPDATE) para as regras puras, a validação, o escopo, as recusas antes do checklist e o contrato de
 * reuso; a atualização pelo OnboardingChecklist::update é conferida no MariaDB (Integration\OnboardingRenewalTest).
 */
final class OnboardingRenewalTest extends TestCase
{
    private PDO $pdo;
    private OnboardingRenewal $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE onboarding_item_templates (id INTEGER PRIMARY KEY, code TEXT, renewal_months INTEGER)',
            'CREATE TABLE condominium_onboarding_items (id INTEGER PRIMARY KEY, condominium_id INTEGER, template_id INTEGER, category TEXT, title TEXT,
                status TEXT, expires_at TEXT, notes TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO onboarding_item_templates (id, code, renewal_months) VALUES (1, 'extintores', 12), (2, 'avcb', NULL)");
        $this->pdo->exec("INSERT INTO condominium_onboarding_items (id, condominium_id, template_id, category, title, status, expires_at) VALUES
            (10, 1, 1, 'seguranca', 'Extintores', 'regular', '2026-11-01'), (11, 1, 2, 'seguranca', 'AVCB', 'regular', '2026-10-01'),
            (12, 1, NULL, 'documentacao', 'Avulso', 'not_applicable', NULL), (13, 3, 1, 'seguranca', 'Extintores', 'regular', '2026-11-01')");
        $this->service = new OnboardingRenewal($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame('2027-11-05', OnboardingRenewal::newExpiry('2026-11-05', 12, null));
        self::assertSame('2027-02-28', OnboardingRenewal::newExpiry('2026-08-31', 6, null));
        self::assertSame('2031-01-01', OnboardingRenewal::newExpiry('2026-11-05', 12, '2031-01-01'));
        self::assertNull(OnboardingRenewal::newExpiry('2026-11-05', null, null));
        $row = ['status' => 'regular', 'renewal_months' => 12];
        self::assertNull(OnboardingRenewal::problem($row, '2026-11-05', null));
        self::assertStringContainsString('não encontrado', (string)OnboardingRenewal::problem(null, '2026-11-05', null));
        self::assertStringContainsString('não se aplica', (string)OnboardingRenewal::problem(['status' => 'not_applicable'] + $row, '2026-11-05', null));
        self::assertStringContainsString('informe a nova validade', (string)OnboardingRenewal::problem(['renewal_months' => null] + $row, '2026-11-05', null));
        self::assertNull(OnboardingRenewal::problem(['renewal_months' => null] + $row, '2026-11-05', '2031-11-05'));
        self::assertStringContainsString('depois da data', (string)OnboardingRenewal::problem($row, '2026-11-05', '2026-11-05'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['item_ids' => [10], 'renewed_on' => '2026-11-05'];
        foreach ([[1, ['renewed_on' => '05/11/2026'] + $ok], [1, ['renewed_on' => date('Y-m-d', strtotime('+2 days'))] + $ok], [1, ['expires_at' => '2031-02-30'] + $ok],
            [1, ['item_ids' => []] + $ok], [1, ['item_ids' => ['x']] + $ok], [1, ['item_ids' => range(1, 101)] + $ok], ['x', $ok], [0, $ok]] as [$condominium, $input]) {
            try {
                $this->service->renew($this->user(), $condominium, $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->renew($this->user(), 3, $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testRefusedBeforeTheChecklist(): void
    {
        foreach ([[[11], null, 'informe a nova validade'], [[12], '2031-01-01', 'não se aplica'], [[13], null, 'não encontrado'], [[99], null, 'não encontrado'],
            [[11], '2026-11-01', 'depois da data']] as [$ids, $expires, $message]) {
            try {
                $this->service->renew($this->user(), 1, ['item_ids' => $ids, 'renewed_on' => '2026-11-05', 'expires_at' => $expires]);
                self::fail('Item ' . $ids[0] . ' passou.');
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$ids[0], $exception->errors['_item']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('2026-11-01', $this->pdo->query('SELECT expires_at FROM condominium_onboarding_items WHERE id = 10')->fetchColumn());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/OnboardingRenewal.php');
        foreach (["\$checklist->update((int)\$condominium, \$id, ['status' => 'regular', 'expires_at' => \$expiry], (int)\$user->id)", 'ContractRenewal::renewedEnd(',
            ' FOR UPDATE', "Audit::record('renew'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['UPDATE condominium_onboarding_items', 'INSERT ', 'mail_queue', 'NotificationOutbox', 'responsible', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, explode('final class', $service)[1], 'Só pelo checklist e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/OnboardingRenewal.php');
        foreach (["Access::can('erp.access', \$user) || !Access::can('erp.condominiums.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/condominiums/{id}/onboarding/renew", "OnboardingRenewal:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
