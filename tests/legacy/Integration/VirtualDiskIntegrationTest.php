<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\VirtualDisk;

/** Disco Virtual: envio validado pelo conteúdo, guarda privada, duplicidade, download e escopo (ADR 0047). */
final class VirtualDiskIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private string $root;
    private string $work;
    private VirtualDisk $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->root = sys_get_temp_dir() . '/vd-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/vd-work-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0700, true);
        $this->disk = new VirtualDisk($this->pdo, null, $this->root, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->work] as $dir) {
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function testUploadListDownloadDuplicateAndDelete(): void
    {
        $document = $this->disk->upload($this->admin, $this->file('Ata da assembleia.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            ['condominium_id' => $this->alpha, 'folder' => 'atas']);
        self::assertSame(['application/pdf', 'Ata da assembleia', 'Ata da assembleia.pdf'], [$document['mime'], $document['title'], $document['original_name']]);
        $file = $this->disk->file($this->admin, $document['id']);
        self::assertStringStartsWith($this->root . '/' . $this->alpha . '/', $file['path'], 'Guardado na pasta privada, com nome gerado.');
        self::assertStringNotContainsString('Ata', basename($file['path']));
        self::assertSame(hash_file('sha256', $file['path']), $document['sha256']);

        try {
            $this->disk->upload($this->admin, $this->file('copia.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), ['condominium_id' => $this->alpha, 'folder' => 'atas']);
            self::fail('Aceitou o mesmo arquivo duas vezes na pasta.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, $this->disk->list($this->admin, $this->alpha, ['folder' => 'atas', 'q' => 'assembleia'])['total']);

        $this->disk->delete($this->admin, $document['id']);
        self::assertSame(0, $this->disk->list($this->admin, $this->alpha)['total']);
        self::assertFileExists($file['path'], 'Exclusão lógica: o arquivo continua guardado.');
        $this->expectException(RegistryNotFound::class);
        $this->disk->file($this->admin, $document['id']);
    }

    public function testRejectsDisguisedOrInvalidFiles(): void
    {
        foreach ([
            [$this->file('nota.pdf', "<?php echo 'x';"), ['folder' => 'notas_fiscais']],          // PHP com nome de PDF
            [$this->file('pagina.html', '<html><script>alert(1)</script></html>'), ['folder' => 'outros']],
            [$this->file('lista.txt', 'ok'), ['folder' => 'gaveta']],                              // pasta inexistente
            [['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], ['folder' => 'atas']],
            [['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE], ['folder' => 'atas']],
        ] as [$upload, $input]) {
            try {
                $this->disk->upload($this->admin, $upload, $input + ['condominium_id' => $this->alpha]);
                self::fail('Aceitou arquivo inválido: ' . ($upload['name'] ?? ''));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('text/plain', $this->disk->upload($this->admin, $this->file('lista.txt', "linha 1\nlinha 2\n"),
            ['condominium_id' => $this->alpha, 'folder' => 'outros', 'title' => 'Lista'])['mime']);

        // Sem o modo de arquivo local (como em produção), só upload HTTP vale.
        $strict = new VirtualDisk($this->pdo, null, $this->root);
        $this->expectException(InvalidArgumentException::class);
        $strict->upload($this->admin, $this->file('outra.txt', 'abc'), ['condominium_id' => $this->alpha, 'folder' => 'outros']);
    }

    public function testScope(): void
    {
        $document = $this->disk->upload($this->admin, $this->file('planta.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
            ['condominium_id' => $this->alpha, 'folder' => 'plantas']);
        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->disk->list($outsider)['total']);
        foreach ([fn () => $this->disk->file($outsider, $document['id']), fn () => $this->disk->upload($outsider, $this->file('a.txt', 'a'), ['condominium_id' => $this->alpha, 'folder' => 'outros'])] as $call) {
            try {
                $call();
                self::fail('Acessou arquivo de condomínio fora do escopo.');
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array{name:string, tmp_name:string, error:int, size:int} */
    private function file(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
