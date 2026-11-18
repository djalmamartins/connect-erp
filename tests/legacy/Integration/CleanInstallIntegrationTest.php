<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use PHPUnit\Framework\TestCase;
use Source\Support\CleanInstall;

/**
 * ADR 0396: num banco vazio, estrutura base + catálogos + configuração + migrations atuais. Usa um banco próprio
 * (movesos_clean_install_test), criado e apagado pelo teste, para não mexer no banco da suíte.
 */
final class CleanInstallIntegrationTest extends TestCase
{
    private const DATABASE = 'movesos_clean_install_test';
    private ?\PDO $server = null;

    protected function setUp(): void
    {
        if (filter_var(getenv('MOVESOS_TEST_DB_DISABLED') ?: false, FILTER_VALIDATE_BOOL)) {
            self::markTestSkipped('Sem MariaDB.');
        }
        $host = getenv('MOVESOS_TEST_DB_HOST') ?: '127.0.0.1';
        $this->server = new \PDO("mysql:host={$host};charset=utf8mb4", getenv('MOVESOS_TEST_DB_USER') ?: 'root', getenv('MOVESOS_TEST_DB_PASS') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->server->exec('DROP DATABASE IF EXISTS ' . self::DATABASE);
        $this->server->exec('CREATE DATABASE ' . self::DATABASE . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        $this->server?->exec('DROP DATABASE IF EXISTS ' . self::DATABASE);
    }

    public function testInstallsAnEmptyDatabaseAndRefusesToRunTwice(): void
    {
        $host = getenv('MOVESOS_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('MOVESOS_TEST_DB_USER') ?: 'root';
        $pass = getenv('MOVESOS_TEST_DB_PASS') ?: '';
        $pdo = new \PDO("mysql:host={$host};dbname=" . self::DATABASE . ';charset=utf8mb4', $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $root = dirname(__DIR__, 3);

        $report = CleanInstall::run($pdo, $root, 'https://www.connectcondominios.com.br');

        self::assertSame(substr_count((string)file_get_contents($root . '/database/base/schema.sql'), "\nCREATE TABLE "), $report['tables']);
        self::assertTrue($report['settings']);
        self::assertLessThanOrEqual(5, count($report['legacy_skipped']), implode("\n", $report['legacy_skipped']));
        $roles = $pdo->query('SELECT slug FROM access_roles ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['client_admin', 'developer', 'manager', 'operator', 'super_admin', 'user'] as $slug) {
            self::assertContains($slug, $roles);
        }
        $settings = $pdo->query('SELECT site_domain_ssl, view_erp, view_app, timezone_set FROM settings WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['site_domain_ssl' => 'https://www.connectcondominios.com.br', 'view_erp' => 'connect', 'view_app' => 'connect', 'timezone_set' => 'America/Sao_Paulo'], $settings);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'nenhuma pessoa na instalação limpa');

        $env = 'MOVESOS_ENV=testing MOVESOS_DB_HOST=' . escapeshellarg($host) . ' MOVESOS_DB_NAME=' . self::DATABASE
            . ' MOVESOS_DB_USER=' . escapeshellarg($user) . ' MOVESOS_DB_PASS=' . escapeshellarg($pass);
        exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/service/commands/database-migrate.php') . ' apply 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", array_slice($out, -5)));
        self::assertGreaterThan(100, (int)$pdo->query('SELECT COUNT(*) FROM access_permissions')->fetchColumn());

        $this->expectException(\DomainException::class);
        CleanInstall::run($pdo, $root);
    }
}
