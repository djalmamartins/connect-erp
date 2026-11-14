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
use Source\Services\Erp\VirtualDiskDeduplication;
use Source\Services\Erp\VirtualDiskRestore;

/**
 * ADR 0277: arquivos do Disco Virtual com vínculo de volta da lixeira, em lote. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas (uma por caso,
 * sem título, nome, caminho nem hash na mensagem), a prévia sem ids (iguais na mesma pasta ficam de fora) e o contrato de
 * reuso; a restauração gravada, com auditoria, é conferida no MariaDB (Integration\VirtualDiskRestoreTest). Os cadastros
 * vêm de VirtualDiskDeduplication::LINKS.
 */
final class VirtualDiskRestoreTest extends TestCase
{
    private PDO $pdo;
    private VirtualDiskRestore $service;

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
        $tables = ['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, folder TEXT, title TEXT, original_name TEXT, path TEXT, size_bytes INTEGER, sha256 TEXT,
                deleted_at TEXT, deleted_by INTEGER)'];
        foreach (VirtualDiskDeduplication::LINKS as $table => $column) {
            $tables[] = "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, {$column} INTEGER)";
        }
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare('INSERT INTO erp_documents (id, condominium_id, folder, title, original_name, path, size_bytes, sha256, deleted_at, deleted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $trash = '2026-01-01 00:00:00';
        foreach ([[1, 1, 'atas', 100, 'hash-a', null], [2, 1, 'atas', 100, 'hash-a', $trash], [3, 1, 'outros', 40, 'hash-b', $trash], [4, 1, 'outros', 70, 'hash-c', $trash],
            [5, 1, 'contratos', 30, 'hash-d', $trash], [6, 1, 'contratos', 30, 'hash-d', $trash], [7, 3, 'atas', 100, 'hash-e', $trash],
            [8, 1, 'atas', 20, 'hash-f', null]] as [$id, $c, $folder, $bytes, $hash, $deleted]) {
            $insert->execute([$id, $c, $folder, 'Ata da assembleia de Fulano', 'ata-fulano.pdf', 'storage/1/2026/x.pdf', $bytes, $hash, $deleted, $deleted === null ? null : 7]);
        }
        // Dois cadastros quaisquer de LINKS (o primeiro e o último), sem fixar a lista.
        [$first, $last] = [array_key_first(VirtualDiskDeduplication::LINKS), array_key_last(VirtualDiskDeduplication::LINKS)];
        foreach ([[$first, 2], [$first, 3], [$last, 3], [$last, 5], [$first, 6], [$first, 7], [$last, 8]] as [$table, $document]) {
            $column = VirtualDiskDeduplication::LINKS[$table];
            $this->pdo->prepare("INSERT INTO {$table} ({$column}) VALUES (?)")->execute([$document]);
        }
        $this->service = new VirtualDiskRestore($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemAndTotalsPureRules(): void
    {
        $row = ['id' => 3, 'deleted' => true];
        self::assertNull(VirtualDiskRestore::problem($row, 1, false));
        self::assertStringContainsString('não encontrado', (string)VirtualDiskRestore::problem(null, 1, false));
        self::assertStringContainsString('não está na lixeira', (string)VirtualDiskRestore::problem(['deleted' => false] + $row, 1, true));
        self::assertStringContainsString('não está ligado', (string)VirtualDiskRestore::problem($row, 0, false));
        self::assertStringContainsString('arquivo igual', (string)VirtualDiskRestore::problem($row, 2, true));
        self::assertSame([3 => 3, 5 => 1], VirtualDiskRestore::totals(['a' => [3 => 2], 'b' => [3 => 1, 5 => 1]]), 'Soma os vínculos de todos os cadastros.');
        self::assertSame([], VirtualDiskRestore::totals([]));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Cadastros sem arquivo'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('x', 256)] + $ok, ['document_ids' => []] + $ok,
            ['document_ids' => ['x']] + $ok, ['document_ids' => 'abc'] + $ok, ['document_ids' => range(1, VirtualDiskRestore::MAX_DOCUMENTS + 1)] + $ok] as $input) {
            try {
                $this->service->restore($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->restore($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedAllOrNothing(): void
    {
        foreach ([[[3, 2], '2', 'arquivo igual'], [[3, 4], '4', 'não está ligado'], [[3, 7], '7', 'não encontrado'], [[3, 8], '8', 'não está na lixeira'],
            [[3, 1], '1', 'não está na lixeira'], [[3, 99], '99', 'não encontrado'], [[6, 5], '6', 'arquivo igual']] as [$ids, $refused, $message]) {
            try {
                $this->service->restore($this->user(), ['condominium_id' => 1, 'document_ids' => $ids, 'reason' => 'Cadastros sem arquivo']);
                self::fail("Arquivo {$refused} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame($refused, $exception->errors['_document'], 'O 3 (e o 5, o mais antigo de dois iguais) passa; a recusa é a do outro.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Fulano', 'ata-fulano', 'storage', 'hash-'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(6, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents WHERE deleted_at IS NOT NULL')->fetchColumn(), 'Nada saiu da lixeira.');
    }

    public function testDryRunWithoutIds(): void
    {
        $result = $this->service->restore($this->user(), ['condominium_id' => '1', 'reason' => 'Cadastros sem arquivo', 'dry_run' => true]);
        self::assertSame([3, 5], array_column($result['documents'], 'id'), 'O 2 tem um igual fora da lixeira; o 6 é igual ao 5, que volta antes; o 4 não tem vínculo.');
        self::assertSame([2, 1], array_column($result['documents'], 'links'));
        self::assertSame([2, 2, 3, 70, 0], [$result['skipped_duplicate'], $result['count'], $result['links'], $result['bytes'], $result['changed']]);
        self::assertSame($result['links'], array_sum(array_column($result['documents'], 'links')), 'O total é a soma das linhas.');
        self::assertSame(6, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents WHERE deleted_at IS NOT NULL')->fetchColumn(), 'A prévia desfaz tudo.');
        self::assertFalse($this->pdo->inTransaction());
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'ata-fulano', 'storage', 'hash-'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VirtualDiskRestore.php');
        foreach (['$this->links->linkCounts(', ' FOR UPDATE', "Audit::record('restore'", '$this->pdo->rollBack()', '$this->scope->assertAccess(',
            'SET deleted_at = NULL, deleted_by = NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'DELETE ', 'unlink', 'rmdir', 'copy(', 'NotificationOutbox', 'mail_queue', 'title', 'original_name', "'path'", "'sha256' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só tira da lixeira, sem apagar nem copiar arquivo e sem título, nome ou hash na resposta: ' . $needle);
        }
        $links = (string)file_get_contents($root . '/source/Services/Erp/VirtualDiskLinks.php');
        self::assertStringContainsString('foreach (VirtualDiskDeduplication::LINKS as $table => $column)', $links, 'Os cadastros vêm de LINKS.');
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VirtualDiskRestore.php');
        foreach (["Access::can('erp.documents.view', \$user)", "Access::can('erp.documents.manage', \$user)", 'catch (CondominiumAccessDenied | RegistryNotFound)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/documents/restore-linked", "VirtualDiskRestore:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/document-links"'), strpos($index, $route), 'Logo depois do relatório de vínculos.');
        self::assertLessThan(strpos($index, '"/documents/{id}"'), strpos($index, $route), 'Antes das rotas /documents/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/document-links:\n"), strpos($openapi, "  /documents/restore-linked:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
