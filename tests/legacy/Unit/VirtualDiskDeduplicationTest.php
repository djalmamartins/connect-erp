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

/**
 * ADR 0275: cópias repetidas do Disco Virtual para a lixeira, em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura (a cópia que fica é a mais antiga; recusas), a validação,
 * o escopo, as recusas antes da exclusão (uma por caso, sem título nem nome na mensagem), os ligados a outro cadastro de
 * fora e o contrato de reuso; a exclusão lógica pelo Disco Virtual, com auditoria, é conferida no MariaDB
 * (Integration\VirtualDiskDeduplicationTest).
 */
final class VirtualDiskDeduplicationTest extends TestCase
{
    private PDO $pdo;
    private VirtualDiskDeduplication $service;

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
                deleted_at TEXT, shared_with_residents INTEGER DEFAULT 0)'];
        foreach (VirtualDiskDeduplication::LINKS as $table => $column) {
            $tables[] = "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, {$column} INTEGER)";
        }
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare('INSERT INTO erp_documents (id, condominium_id, folder, title, original_name, path, size_bytes, sha256, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([[1, 1, 'atas', 100, 'hash-a', null], [2, 1, 'outros', 100, 'hash-a', null], [3, 1, 'contratos', 100, 'hash-a', null], [4, 1, 'atas', 50, 'hash-b', null],
            [5, 1, 'plantas', 70, 'hash-c', null], [6, 1, 'outros', 70, 'hash-c', '2026-01-01 00:00:00'], [7, 3, 'atas', 100, 'hash-a', null],
            [8, 1, 'notas_fiscais', 30, 'hash-d', null], [9, 1, 'outros', 30, 'hash-d', null]] as [$id, $c, $folder, $bytes, $hash, $deleted]) {
            $insert->execute([$id, $c, $folder, 'Ata da assembleia de Fulano', 'ata-fulano.pdf', 'storage/1/2026/x.pdf', $bytes, $hash, $deleted]);
        }
        $this->pdo->exec('INSERT INTO erp_contracts (document_id) VALUES (3)');
        $this->pdo->exec('INSERT INTO erp_assembly_attendance (proxy_document_id) VALUES (9)');
        $this->service = new VirtualDiskDeduplication($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testKeepersAndProblemPureRules(): void
    {
        self::assertSame([7 => 3, 3 => 3, 9 => 3], VirtualDiskDeduplication::keepers([['id' => 7, 'sha256' => 'a'], ['id' => 3, 'sha256' => 'a'], ['id' => 5, 'sha256' => 'b'],
            ['id' => 9, 'sha256' => 'a']]), 'Fica a mais antiga (menor id), qualquer que seja a ordem; conteúdo sem cópia não entra.');
        self::assertSame([], VirtualDiskDeduplication::keepers([]));
        $row = ['id' => 7];
        self::assertNull(VirtualDiskDeduplication::problem($row, 3, false));
        self::assertStringContainsString('não encontrado', (string)VirtualDiskDeduplication::problem(null, null, false));
        self::assertStringContainsString('não tem outra cópia', (string)VirtualDiskDeduplication::problem($row, null, false));
        self::assertStringContainsString('cópia que fica', (string)VirtualDiskDeduplication::problem(['id' => 3], 3, false));
        self::assertStringContainsString('ligado a outro cadastro', (string)VirtualDiskDeduplication::problem($row, 3, true));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Limpeza das cópias'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['document_ids' => []] + $ok, ['document_ids' => ['x']] + $ok,
            ['document_ids' => 'abc'] + $ok, ['document_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->trash($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->trash($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheDeletion(): void
    {
        foreach ([[1, 'cópia que fica'], [3, 'ligado a outro cadastro'], [4, 'não tem outra cópia'], [5, 'não tem outra cópia'], [6, 'não encontrado'],
            [7, 'não encontrado'], [9, 'ligado a outro cadastro'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->trash($this->user(), ['condominium_id' => 1, 'document_ids' => [2, $id], 'reason' => 'Limpeza das cópias']);
                self::fail("Arquivo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_document'], 'O 2 é cópia livre e passa; a recusa é a do outro.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Fulano', 'ata-fulano', 'storage', 'hash-'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents WHERE id IN (1, 2, 3) AND deleted_at IS NOT NULL')->fetchColumn(), 'Nada foi para a lixeira.');
    }

    public function testWithoutIdsTheLinkedAreCountedAndLeftOut(): void
    {
        // O 2 também ligado (a um malote): sem cópia livre, o lote não chega a excluir nada.
        $this->pdo->exec('INSERT INTO erp_pouches (document_id) VALUES (2)');
        $result = $this->service->trash($this->user(), ['condominium_id' => '1', 'reason' => 'Limpeza das cópias', 'dry_run' => true]);
        self::assertSame([3, 0, 0, 0, 0], [$result['skipped_linked'], $result['count'], $result['groups'], $result['bytes'], $result['changed']]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VirtualDiskDeduplication.php');
        foreach (['$this->disk->delete($user, ', ' FOR UPDATE', "Audit::record('trash_duplicate'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'unlink', 'rmdir', 'NotificationOutbox', 'mail_queue', 'title', 'original_name', "'path'", "'sha256' => \$row"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pela exclusão lógica do Disco Virtual, sem apagar arquivo e sem título, nome ou hash na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VirtualDiskDeduplication.php');
        foreach (["Access::can('erp.documents.view', \$user)", "Access::can('erp.documents.manage', \$user)", 'catch (CondominiumAccessDenied | RegistryNotFound)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/documents/trash-duplicates", "VirtualDiskDeduplication:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/virtual-disk"'), strpos($index, $route), 'Logo depois do inventário do Disco Virtual.');
        self::assertLessThan(strpos($index, '"/documents/{id}"'), strpos($index, $route), 'Antes das rotas /documents/{id}.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
