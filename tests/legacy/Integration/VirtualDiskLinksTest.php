<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskDeduplication;
use Source\Services\Erp\VirtualDiskLinks;

/** ADR 0276: vínculos dos arquivos do Disco Virtual com envios, exclusão e um malote de verdade; somente leitura e sem título, nome ou hash. */
final class VirtualDiskLinksTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/vdl-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/vdl-work-' . bin2hex(random_bytes(4));
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

    public function testLinksFromRealUploadsAndAPouch(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $linked = $this->upload('Protocolo do malote.pdf', $pdf, 'outros');
        $loose = $this->upload('Avulso.pdf', $pdf . "% avulso\n", 'outros');
        $trashed = $this->upload('Protocolo antigo.pdf', $pdf . "% antigo\n", 'outros');
        $this->pouch((int)$linked['id']);
        $this->pouch((int)$trashed['id']);
        $this->disk->delete($this->admin, (int)$trashed['id']);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new VirtualDiskLinks($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(array_keys(VirtualDiskDeduplication::LINKS), array_column($report['registries'], 'registry'), 'Os cadastros vêm de LINKS.');
        $folders = array_combine(array_column($report['folders'], 'folder'), $report['folders']);
        self::assertSame([2, 1, 1], [$folders['outros']['documents'], $folders['outros']['linked'], $folders['outros']['loose']]);
        $registries = array_combine(array_column($report['registries'], 'registry'), $report['registries']);
        self::assertSame([1, 1, 1], [$registries['erp_pouches']['documents'], $registries['erp_pouches']['links'], $registries['erp_pouches']['broken_links']]);
        self::assertSame(['links' => 1, 'documents' => 1, 'document_ids' => [(int)$trashed['id']], 'truncated' => false], $report['broken'],
            'O malote aponta para um arquivo que foi para a lixeira.');
        self::assertNotContains((int)$loose['id'], $report['broken']['document_ids'], 'O avulso não tem vínculo.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Protocolo do malote', 'Avulso', '.pdf', (string)$linked['sha256'], $this->root] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VirtualDiskLinks($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function upload(string $name, string $content, string $folder): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return $this->disk->upload($this->admin, ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)],
            ['condominium_id' => $this->condominium, 'folder' => $folder]);
    }

    private function pouch(int $document): void
    {
        $this->pdo->prepare("INSERT INTO erp_pouches (condominium_id, sent_on, courier, documents_count, contents, document_id) VALUES (?, ?, 'Portador de teste', 1, 'Teste', ?)")
            ->execute([$this->condominium, date('Y-m-d'), $document]);
    }
}
