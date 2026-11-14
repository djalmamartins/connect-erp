<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskInventory;

/**
 * ADR 0274: inventário do Disco Virtual, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura (as faixas de idade com as bordas de 90 e 365 dias, as pastas do Disco Virtual, o antivírus, as cópias entre
 * pastas, a lixeira, totais iguais à soma), a montagem sem título, nome, caminho ou hash (de outro condomínio de fora), a
 * validação, o escopo e o contrato; o Disco Virtual de verdade é conferido no MariaDB (Integration\VirtualDiskInventoryTest).
 */
final class VirtualDiskInventoryTest extends TestCase
{
    private PDO $pdo;
    private VirtualDiskInventory $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, folder TEXT, title TEXT, original_name TEXT, path TEXT, mime TEXT, size_bytes INTEGER,
                sha256 TEXT, scan_status TEXT, uploaded_by INTEGER, created_at TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new VirtualDiskInventory($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testAgeBucketAndSummarizePureRules(): void
    {
        $today = '2026-11-14';
        self::assertSame('ate_90', VirtualDiskInventory::ageBucket('2026-08-16 10:00:00', $today), '90 dias ainda é até 90.');
        self::assertSame('de_91_a_365', VirtualDiskInventory::ageBucket('2026-08-15 23:59:59', $today));
        self::assertSame('de_91_a_365', VirtualDiskInventory::ageBucket('2025-11-14 00:00:00', $today), '365 dias ainda é até 365.');
        self::assertSame('mais_de_365', VirtualDiskInventory::ageBucket('2025-11-13 00:00:00', $today));
        self::assertSame('ate_90', VirtualDiskInventory::ageBucket('2026-11-20 00:00:00', $today), 'Envio no futuro (relógio) conta como recente.');

        $d = static fn (string $folder, int $bytes, string $hash, string $scan, string $created, bool $deleted = false): array => ['folder' => $folder,
            'size_bytes' => $bytes, 'sha256' => $hash, 'scan_status' => $scan, 'created_at' => $created, 'deleted' => $deleted];
        $summary = VirtualDiskInventory::summarize([$d('atas', 100, 'h1', 'clean', '2026-11-01'), $d('outros', 300, 'h1', 'unscanned', '2026-01-01'),
            $d('atas', 50, 'h2', 'clean', '2026-11-01', true), $d('desconhecida', 999, 'h3', 'clean', '2026-11-01')], $today);
        self::assertSame(array_keys(VirtualDisk::FOLDERS), array_column($summary['rows'], 'folder'), 'Sempre as pastas do Disco Virtual.');
        foreach (array_merge(['documents', 'bytes'], ScanStatus::ALL) as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertEquals(['documents' => 2, 'bytes' => 400, 'clean' => 1, 'infected' => 0, 'unscanned' => 1, 'error' => 0], $summary['totals'],
            'Pasta desconhecida fica de fora.');
        self::assertSame(['documents' => 2, 'bytes' => 400, 'clean' => 1, 'infected' => 0, 'unscanned' => 1, 'error' => 0],
            ['documents' => $summary['totals']['documents'], 'bytes' => $summary['totals']['bytes']] + array_intersect_key($summary['totals'], array_flip(ScanStatus::ALL)));
        $odd = VirtualDiskInventory::summarize([$d('atas', 1, 'z', 'desconhecido', '2026-11-01')], $today);
        self::assertSame(1, $odd['totals'][ScanStatus::ERROR], 'Situação desconhecida conta como erro.');
        self::assertSame(['ate_90' => 1, 'de_91_a_365' => 1, 'mais_de_365' => 0], $summary['by_age']);
        self::assertSame(['groups' => 1, 'extra_copies' => 1, 'extra_bytes' => 300], $summary['duplicates']);
        self::assertSame(['documents' => 1, 'bytes' => 50], $summary['trash']);
        $empty = VirtualDiskInventory::summarize([], $today);
        self::assertSame([count(VirtualDisk::FOLDERS), 0, 0, 0], [count($empty['rows']), $empty['totals']['documents'], $empty['duplicates']['groups'], $empty['trash']['documents']]);
    }

    public function testBuildWithoutTitlesNamesPathsOrHashes(): void
    {
        $ago = static fn (int $days): string => date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $insert = $this->pdo->prepare('INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256, scan_status, uploaded_by,
            created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 7, ?, ?)');
        foreach ([[1, 'atas', 1000, 'hash-a', 'clean', $ago(10), null], [1, 'contratos', 2000, 'hash-a', 'clean', $ago(100), null],
            [1, 'contratos', 3000, 'hash-b', 'unscanned', $ago(400), null], [1, 'notas_fiscais', 500, 'hash-c', 'infected', $ago(5), null],
            [1, 'outros', 700, 'hash-a', 'error', $ago(10), null], [1, 'plantas', 800, 'hash-d', 'clean', $ago(10), $ago(1)],
            [3, 'atas', 9999, 'hash-a', 'clean', $ago(10), null]] as [$c, $folder, $bytes, $hash, $scan, $created, $deleted]) {
            $insert->execute([$c, $folder, 'Ata da assembleia de Fulano', 'ata-fulano.pdf', 'storage/1/2026/abc.pdf', 'application/pdf', $bytes, $hash, $scan, $created, $deleted]);
        }
        $report = $this->service->build($this->user(), '1');
        self::assertSame(['atas' => [1, 1000], 'contratos' => [2, 5000], 'plantas' => [0, 0], 'notas_fiscais' => [1, 500], 'comprovantes' => [0, 0], 'outros' => [1, 700]],
            array_combine(array_column($report['rows'], 'folder'), array_map(static fn (array $r): array => [$r['documents'], $r['bytes']], $report['rows'])));
        self::assertEquals(['documents' => 5, 'bytes' => 7200, 'clean' => 2, 'unscanned' => 1, 'infected' => 1, 'error' => 1], $report['totals'], 'Mesmos valores (ordem das chaves livre).');
        self::assertSame(['ate_90' => 3, 'de_91_a_365' => 1, 'mais_de_365' => 1], $report['by_age']);
        self::assertSame(array_sum($report['by_age']), $report['totals']['documents']);
        self::assertSame(['groups' => 1, 'extra_copies' => 2, 'extra_bytes' => 2700], $report['duplicates'], 'O mesmo conteúdo em três pastas.');
        self::assertSame(['documents' => 1, 'bytes' => 800], $report['trash']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Ata da assembleia', 'Fulano', 'ata-fulano', 'storage', 'hash-', 'application/pdf', 'uploaded'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem título, nome, caminho ou hash: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, '', 'x', 0, '-1', 1.5, []] as $condominium) {
            try {
                $this->service->build($this->user(), $condominium);
                self::fail('Entrada inválida passou: ' . json_encode($condominium));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/VirtualDiskInventory.php');
        foreach (['$this->scope->assertAccess(', 'VirtualDisk::FOLDERS'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'title', 'original_name', 'path', 'uploaded_by', 'file_get_contents', 'fopen', 'NotificationOutbox'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura, sem abrir arquivo e sem título ou nome: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VirtualDiskInventory.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.documents.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/virtual-disk", "VirtualDiskInventory:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/cost-centers/deactivate-idle"'), strpos($index, $route), 'Logo depois da inativação dos centros de custo.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
