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
use Source\Services\Erp\TariffReadjustment;

/**
 * ADR 0259: reajuste em lote das tarifas de consumo. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de
 * teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas e o que fica de fora sem tipos, e o
 * contrato de reuso; as faixas novas criadas pelo cadastro, com auditoria, são conferidas no MariaDB
 * (Integration\TariffReadjustmentTest).
 */
final class TariffReadjustmentTest extends TestCase
{
    private PDO $pdo;
    private TariffReadjustment $service;

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
            'CREATE TABLE erp_tariffs (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, name TEXT, from_amount REAL, to_amount REAL, price_cents INTEGER, valid_from TEXT,
                notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_tariffs (id, condominium_id, kind, name, from_amount, to_amount, price_cents, valid_from, notes, status, deleted_at) VALUES
            (1, 1, 'agua', 'Faixa 1', 0, 10, 500, '2026-01-01', NULL, 'active', NULL), (2, 1, 'gas', 'Gás', 0, NULL, 900, '2026-01-01', NULL, 'active', NULL),
            (3, 1, 'gas', 'Gás novo', 0, NULL, 990, '2099-01-01', NULL, 'active', NULL), (4, NULL, 'energia', 'Padrão', 0, NULL, 100, '2026-01-01', NULL, 'active', NULL),
            (5, 1, 'energia', 'Inativa', 0, NULL, 100, '2026-01-01', NULL, 'inactive', NULL), (6, 3, 'energia', 'De outro', 0, NULL, 100, '2026-01-01', NULL, 'active', NULL)");
        $this->service = new TariffReadjustment($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        self::assertNull(TariffReadjustment::problem(['2025-01-01', '2026-01-01'], '2026-12-01'));
        self::assertStringContainsString('padrão da Connect', (string)TariffReadjustment::problem([], '2026-12-01'));
        self::assertStringContainsString('padrão da Connect', (string)TariffReadjustment::problem(['2026-12-01'], '2026-12-01'), 'Só a vigência na data não é anterior.');
        self::assertStringContainsString('nesta data ou depois', (string)TariffReadjustment::problem(['2026-01-01', '2026-12-01'], '2026-12-01'));
        self::assertStringContainsString('nesta data ou depois', (string)TariffReadjustment::problem(['2026-01-01', '2027-01-01 00:00:00'], '2026-12-01'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'percent' => '5,5', 'valid_from' => '2098-01-01', 'reason' => 'Reajuste da concessionária'];
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach ([['condominium_id' => 0] + $ok, ['percent' => '0'] + $ok, ['percent' => '101'] + $ok, ['percent' => '-51'] + $ok, ['percent' => 'x'] + $ok,
            ['valid_from' => '2098-02-30'] + $ok, ['valid_from' => '01/01/2098'] + $ok, ['valid_from' => $yesterday] + $ok, ['reason' => 'abc'] + $ok,
            ['kinds' => []] + $ok, ['kinds' => ['luz']] + $ok, ['kinds' => 'agua'] + $ok] as $input) {
            try {
                $this->service->readjust($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->readjust($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenKindsRefused(): void
    {
        $ok = ['condominium_id' => 1, 'percent' => '5,5', 'valid_from' => '2098-01-01', 'reason' => 'Reajuste da concessionária'];
        foreach ([['gas', 'nesta data ou depois'], ['energia', 'padrão da Connect']] as [$kind, $message]) {
            try {
                $this->service->readjust($this->user(), ['kinds' => [$kind]] + $ok);
                self::fail("Tipo {$kind} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame($kind, $exception->errors['_kind']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(6, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_tariffs')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutKindsTheOthersAreLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_tariffs SET deleted_at = '2026-01-01 00:00:00' WHERE id = 1");
        $result = $this->service->readjust($this->user(), ['condominium_id' => '1', 'percent' => 10, 'valid_from' => '2098-01-01', 'reason' => 'Reajuste da concessionária',
            'dry_run' => true]);
        self::assertSame([['agua', 'energia'], ['gas'], [], 0, 0, '10,00'], [$result['skipped_default'], $result['skipped_already'], $result['kinds'], $result['count'],
            $result['changed'], $result['percent']]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/TariffReadjustment.php');
        foreach (["\$registry->save(\$user, 'tariffs', ", 'RecurringReadjustment::basisPoints(', 'RecurringReadjustment::readjusted(', ' FOR UPDATE',
            "Audit::record('readjust'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('tariffs')"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário e sem aviso: ' . $needle);
        }
        $line = explode("\$line['bands'][] = ", $service)[1];
        foreach (['name', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, explode(';', $line)[0], 'Sem nome da faixa nem observação na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/TariffReadjustment.php');
        foreach (["Access::can('erp.tariffs.view', \$user)", "Access::can('erp.tariffs.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/tariffs/readjust", "TariffReadjustment:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
