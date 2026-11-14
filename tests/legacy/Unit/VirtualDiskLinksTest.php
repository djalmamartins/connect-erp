<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskDeduplication;
use Source\Services\Erp\VirtualDiskLinks;

/**
 * ADR 0276: vínculos dos arquivos do Disco Virtual, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2) para a regra pura (ligados e soltos por pasta, arquivos e vínculos por cadastro, vínculos para a lixeira,
 * corte dos ids, totais iguais à soma), a montagem sem título, nome, caminho ou hash (outro condomínio de fora), a
 * validação, o escopo e o contrato. Pastas e cadastros vêm das próprias constantes (VirtualDisk::FOLDERS e
 * VirtualDiskDeduplication::LINKS): nada de quantidade nem lista fixa no teste. O MariaDB confere com os cadastros
 * (Integration\VirtualDiskLinksTest).
 */
final class VirtualDiskLinksTest extends TestCase
{
    private PDO $pdo;
    private VirtualDiskLinks $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = ['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, folder TEXT, title TEXT, original_name TEXT, path TEXT, sha256 TEXT, deleted_at TEXT)'];
        foreach (VirtualDiskDeduplication::LINKS as $table => $column) {
            $tables[] = "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, {$column} INTEGER)";
        }
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new VirtualDiskLinks($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string, 2:string, 3:string} duas pastas e dois cadastros, vindos das constantes */
    private static function pick(): array
    {
        $folders = array_keys(VirtualDisk::FOLDERS);
        $registries = array_keys(VirtualDiskDeduplication::LINKS);
        self::assertGreaterThanOrEqual(2, count($folders));
        self::assertGreaterThanOrEqual(2, count($registries));
        return [$folders[0], $folders[1], $registries[0], $registries[1]];
    }

    public function testSummarizePureRule(): void
    {
        [$a, $b, $r0, $r1] = self::pick();
        $d = static fn (int $id, string $folder, bool $deleted = false): array => ['id' => $id, 'folder' => $folder, 'deleted' => $deleted];
        $summary = VirtualDiskLinks::summarize([$d(1, $a), $d(2, $a), $d(3, $b), $d(4, $a, true), $d(5, 'pasta-desconhecida')],
            [$r0 => [1 => 2, 4 => 1], $r1 => [1 => 1, 3 => 1, 4 => 2, 99 => 5]]);
        self::assertSame(array_keys(VirtualDisk::FOLDERS), array_column($summary['folders'], 'folder'), 'Sempre todas as pastas do Disco Virtual.');
        self::assertSame(array_keys(VirtualDiskDeduplication::LINKS), array_column($summary['registries'], 'registry'), 'Sempre todos os cadastros de LINKS.');
        $folders = array_combine(array_column($summary['folders'], 'folder'), $summary['folders']);
        self::assertSame([2, 1, 1], [$folders[$a]['documents'], $folders[$a]['linked'], $folders[$a]['loose']]);
        self::assertSame([1, 1, 0], [$folders[$b]['documents'], $folders[$b]['linked'], $folders[$b]['loose']]);
        foreach ($summary['folders'] as $row) {
            self::assertSame($row['documents'], $row['linked'] + $row['loose'], 'Ligados + soltos = arquivos da pasta.');
        }
        foreach (['documents', 'linked', 'loose'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['folders'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame(['documents' => 3, 'linked' => 2, 'loose' => 1], $summary['totals'], 'A pasta desconhecida e a lixeira ficam de fora.');
        $registries = array_combine(array_column($summary['registries'], 'registry'), $summary['registries']);
        self::assertSame([1, 2, 1], [$registries[$r0]['documents'], $registries[$r0]['links'], $registries[$r0]['broken_links']]);
        self::assertSame([2, 2, 2], [$registries[$r1]['documents'], $registries[$r1]['links'], $registries[$r1]['broken_links']], 'Arquivo de fora (99) não conta.');
        self::assertSame(['links' => 3, 'documents' => 1, 'document_ids' => [4], 'truncated' => false], $summary['broken']);
        self::assertSame($summary['broken']['links'], array_sum(array_column($summary['registries'], 'broken_links')));

        $many = [];
        $linked = [];
        for ($i = 1; $i <= VirtualDiskLinks::MAX_IDS + 1; $i++) {
            $many[] = $d($i, $a, true);
            $linked[$i] = 1;
        }
        $cut = VirtualDiskLinks::summarize($many, [$r0 => $linked]);
        self::assertSame([VirtualDiskLinks::MAX_IDS + 1, VirtualDiskLinks::MAX_IDS, true], [$cut['broken']['documents'], count($cut['broken']['document_ids']),
            $cut['broken']['truncated']]);
        $empty = VirtualDiskLinks::summarize([], []);
        self::assertSame([0, 0, []], [$empty['totals']['documents'], $empty['broken']['links'], $empty['broken']['document_ids']]);
    }

    public function testBuildWithoutTitlesNamesPathsOrHashes(): void
    {
        [$a, $b, $r0, $r1] = self::pick();
        $insert = $this->pdo->prepare('INSERT INTO erp_documents (id, condominium_id, folder, title, original_name, path, sha256, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([[1, 1, $a, null], [2, 1, $a, null], [3, 1, $b, null], [4, 1, $a, '2026-01-01 00:00:00'], [8, 3, $a, null], [9, 3, $a, '2026-01-01 00:00:00']] as [$id, $c, $folder, $deleted]) {
            $insert->execute([$id, $c, $folder, 'Contrato de Fulano', 'contrato-fulano.pdf', 'storage/1/2026/x.pdf', 'hash-' . $id, $deleted]);
        }
        $c0 = VirtualDiskDeduplication::LINKS[$r0];
        $c1 = VirtualDiskDeduplication::LINKS[$r1];
        $this->pdo->exec("INSERT INTO {$r0} ({$c0}) VALUES (1), (1), (4), (8), (9)");
        $this->pdo->exec("INSERT INTO {$r1} ({$c1}) VALUES (3), (4), (4), (9)");
        $report = $this->service->build($this->user(), '1');
        $folders = array_combine(array_column($report['folders'], 'folder'), $report['folders']);
        self::assertSame([2, 1, 1], [$folders[$a]['documents'], $folders[$a]['linked'], $folders[$a]['loose']]);
        self::assertSame(['documents' => 3, 'linked' => 2, 'loose' => 1], $report['totals']);
        $registries = array_combine(array_column($report['registries'], 'registry'), $report['registries']);
        self::assertSame([1, 2, 1], [$registries[$r0]['documents'], $registries[$r0]['links'], $registries[$r0]['broken_links']], 'Os vínculos do outro condomínio não entram.');
        self::assertSame([1, 1, 2], [$registries[$r1]['documents'], $registries[$r1]['links'], $registries[$r1]['broken_links']]);
        self::assertSame(['links' => 3, 'documents' => 1, 'document_ids' => [4], 'truncated' => false], $report['broken']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'contrato-fulano', 'storage', 'hash-', '.pdf'] as $needle) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/VirtualDiskLinks.php');
        foreach (['$this->scope->assertAccess(', 'VirtualDisk::FOLDERS', 'VirtualDiskDeduplication::LINKS'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'title', 'original_name', 'path', 'sha256', 'NotificationOutbox'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem título, nome, caminho ou hash: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VirtualDiskLinks.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.documents.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/document-links", "VirtualDiskLinks:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/documents/trash-duplicates"'), strpos($index, $route), 'Logo depois das cópias para a lixeira.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
