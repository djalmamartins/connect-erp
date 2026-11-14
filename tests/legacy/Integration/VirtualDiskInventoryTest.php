<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskInventory;

/** ADR 0274: inventário do Disco Virtual com envios e exclusão de verdade; somente leitura e sem título, nome ou hash. */
final class VirtualDiskInventoryTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private string $root;
    private string $work;
    private VirtualDisk $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->root = sys_get_temp_dir() . '/vdi-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/vdi-work-' . bin2hex(random_bytes(4));
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

    public function testInventoryFromRealUploads(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $ata = $this->disk->upload($this->admin, $this->file('Ata da assembleia.pdf', $pdf), ['condominium_id' => $this->condominium, 'folder' => 'atas']);
        // O mesmo conteúdo só é recusado na mesma pasta: em Contratos ele entra, e o inventário conta a cópia.
        $this->disk->upload($this->admin, $this->file('Contrato de portaria.pdf', $pdf), ['condominium_id' => $this->condominium, 'folder' => 'contratos']);
        $old = $this->disk->upload($this->admin, $this->file('Planta antiga.pdf', $pdf . "% planta\n"), ['condominium_id' => $this->condominium, 'folder' => 'plantas']);
        $gone = $this->disk->upload($this->admin, $this->file('Rascunho.pdf', $pdf . "% rascunho\n"), ['condominium_id' => $this->condominium, 'folder' => 'outros']);
        $this->disk->delete($this->admin, (int)$gone['id']);
        // Envio de 400 dias atrás (para trás: INTERVAL positivo subtraído de NOW()).
        $this->pdo->prepare('UPDATE erp_documents SET created_at = NOW() - INTERVAL 400 DAY WHERE id = ?')->execute([(int)$old['id']]);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new VirtualDiskInventory($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['atas' => 1, 'contratos' => 1, 'plantas' => 1, 'notas_fiscais' => 0, 'comprovantes' => 0, 'outros' => 0],
            array_combine(array_column($report['rows'], 'folder'), array_column($report['rows'], 'documents')));
        self::assertSame(3, $report['totals']['documents']);
        self::assertSame((int)$ata['size_bytes'] * 2 + (int)$old['size_bytes'], $report['totals']['bytes']);
        self::assertSame(['ate_90' => 2, 'de_91_a_365' => 0, 'mais_de_365' => 1], $report['by_age']);
        self::assertSame(['groups' => 1, 'extra_copies' => 1, 'extra_bytes' => (int)$ata['size_bytes']], $report['duplicates']);
        self::assertSame(['documents' => 1, 'bytes' => (int)$gone['size_bytes']], $report['trash'], 'A exclusão é lógica: fica na lixeira.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Ata da assembleia', 'Contrato de portaria', '.pdf', (string)$ata['sha256'], $this->root] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VirtualDiskInventory($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function file(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }
}
