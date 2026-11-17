<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\Storage\DocumentStore;
use Source\Support\Storage\DocumentStores;
use Source\Support\Storage\StorageCopy;

/** ADR 0376: copiar os arquivos do servidor para o OneDrive antes da troca, retomando de onde parou. */
final class StorageCopyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/copy-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/root/1/2026', 0777, true);
        mkdir($this->dir . '/root/2/2025', 0777, true);
        file_put_contents($this->dir . '/root/1/2026/a.pdf', 'A');
        file_put_contents($this->dir . '/root/1/2026/b.pdf', 'B');
        file_put_contents($this->dir . '/root/2/2025/c.jpg', 'C');
        file_put_contents($this->dir . '/root/.htaccess', "Require all denied\n");
        symlink('/etc/hostname', $this->dir . '/root/2/link.pdf');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function target(array $fail = []): DocumentStore
    {
        return new class ($fail) implements DocumentStore {
            /** @var array<string, string> */
            public array $files = [];

            public function __construct(private array $fail)
            {
            }

            public function put(string $relative, string $source, bool $move): bool
            {
                if ($move || in_array($relative, $this->fail, true)) {
                    return false;
                }
                $this->files[$relative] = (string)file_get_contents($source);
                return true;
            }

            public function path(string $relative): ?string
            {
                return null;
            }

            public function delete(string $relative): void
            {
            }
        };
    }

    public function testListsOnlyRegularFiles(): void
    {
        self::assertSame(['1/2026/a.pdf', '1/2026/b.pdf', '2/2025/c.jpg'], StorageCopy::files($this->dir . '/root/'));
        self::assertSame([], StorageCopy::files($this->dir . '/nao-existe'));
    }

    public function testDryRunSendsNothingAndRunResumesAfterFailure(): void
    {
        $done = $this->dir . '/done';
        $target = $this->target(['1/2026/b.pdf']);
        self::assertSame(['total' => 3, 'copied' => 0, 'skipped' => 0, 'failed' => []], StorageCopy::run($this->dir . '/root', $target, $done, true));
        self::assertSame([], $target->files);

        self::assertSame(['total' => 3, 'copied' => 2, 'skipped' => 0, 'failed' => ['1/2026/b.pdf']], StorageCopy::run($this->dir . '/root', $target, $done, false));
        self::assertSame(['1/2026/a.pdf' => 'A', '2/2025/c.jpg' => 'C'], $target->files);

        $again = $this->target();
        self::assertSame(['total' => 3, 'copied' => 1, 'skipped' => 2, 'failed' => []], StorageCopy::run($this->dir . '/root', $again, $done, false));
        self::assertSame(['1/2026/b.pdf' => 'B'], $again->files, 'só o que faltou sobe na segunda vez');
        self::assertFileExists($this->dir . '/root/1/2026/a.pdf', 'a pasta local fica intacta');
    }

    public function testOneDriveEvenWithLocalModeButNeverIncomplete(): void
    {
        $env = ['DOCUMENT_STORAGE' => 'local', 'ONEDRIVE_TENANT_ID' => 't', 'ONEDRIVE_CLIENT_ID' => 'c', 'ONEDRIVE_CLIENT_SECRET' => 's',
            'ONEDRIVE_DRIVE_ID' => 'd', 'ONEDRIVE_FOLDER' => 'ConnectERP'];
        $http = $this->createStub(\Source\Support\Storage\GraphTransport::class);
        self::assertStringEndsWith('/root:/ConnectERP/inspections/1/a.jpg:', DocumentStores::oneDrive('inspections', $env, $http)->itemUrl('1/a.jpg'));
        $this->expectException(\InvalidArgumentException::class);
        DocumentStores::oneDrive('documents', ['ONEDRIVE_DRIVE_ID' => ''] + $env, $http);
    }

    public function testScriptRefusesWithoutOneDriveKeys(): void
    {
        $cmd = 'env -u ONEDRIVE_TENANT_ID MOVESOS_DB_HOST=127.0.0.1 MOVESOS_DB_NAME=x MOVESOS_DB_USER=x MOVESOS_DB_PASS=x '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/hosting/onedrive-copy.php') . ' --dry-run 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(2, $code, implode("\n", $out));
        self::assertStringContainsString('ONEDRIVE_TENANT_ID', implode("\n", $out));
    }
}
