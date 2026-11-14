<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskLinks;
use Source\Services\Erp\VirtualDiskRestore;

/** ADR 0277: arquivos com vínculo de volta da lixeira, com envios, exclusões e malotes de verdade; tudo ou nada, auditado e sem título, nome ou hash. */
final class VirtualDiskRestoreTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/vdr-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/vdr-work-' . bin2hex(random_bytes(4));
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

    public function testRestoresLinkedFromTheTrash(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $linked = (int)$this->upload('Protocolo do malote.pdf', $pdf)['id'];
        $twin = (int)$this->upload('Protocolo repetido.pdf', $pdf . "% repetido\n")['id'];
        $loose = (int)$this->upload('Avulso.pdf', $pdf . "% avulso\n")['id'];
        $active = (int)$this->upload('Protocolo atual.pdf', $pdf . "% atual\n")['id'];
        foreach ([$linked, $linked, $twin, $active] as $document) {
            $this->pouch($document);
        }
        foreach ([$linked, $twin, $loose] as $document) {
            $this->disk->delete($this->admin, $document);
        }
        // O mesmo conteúdo do $twin enviado de novo na mesma pasta: o $twin não pode voltar.
        $copy = $this->upload('Protocolo repetido de novo.pdf', $pdf . "% repetido\n");
        $service = new VirtualDiskRestore($this->pdo);
        $reason = ['condominium_id' => $this->condominium, 'reason' => 'Malotes sem protocolo'];

        $preview = $service->restore($this->admin, $reason + ['dry_run' => true]);
        self::assertSame([[$linked], 1, 1, 2, 0], [array_column($preview['documents'], 'id'), $preview['count'], $preview['skipped_duplicate'], $preview['links'], $preview['changed']]);
        self::assertNotNull($this->deletedAt($linked), 'A prévia não tira da lixeira.');

        foreach ([[$loose, 'não está ligado'], [$twin, 'arquivo igual'], [$active, 'não está na lixeira']] as [$document, $message]) {
            try {
                $service->restore($this->admin, $reason + ['document_ids' => [$linked, $document]]);
                self::fail("Arquivo {$document} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$document, $exception->errors['_document']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertNotNull($this->deletedAt($linked), 'Tudo ou nada: o recusado desfaz o lote.');
        }
        $before = (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'restore'")->fetchColumn();

        $result = $service->restore($this->admin, $reason);
        self::assertSame([[$linked], 1, 1, 2, strlen($pdf)], [array_column($result['documents'], 'id'), $result['changed'], $result['skipped_duplicate'], $result['links'],
            $result['bytes']]);
        self::assertNull($this->deletedAt($linked));
        self::assertNull($this->pdo->query("SELECT deleted_by FROM erp_documents WHERE id = {$linked}")->fetchColumn() ?: null);
        self::assertSame($linked, (int)$this->disk->find($this->admin, $linked)['id'], 'Volta para o Disco Virtual.');
        self::assertNotNull($this->deletedAt($twin));
        self::assertNotNull($this->deletedAt($loose));
        self::assertSame($before + 1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'restore'")->fetchColumn());
        $context = (string)$this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'restore' ORDER BY id DESC LIMIT 1")
            ->fetchColumn();
        self::assertStringContainsString('Malotes sem protocolo', $context);
        $report = (new VirtualDiskLinks($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([$twin], $report['broken']['document_ids'], 'Sobra só o vínculo do arquivo que tem um igual fora da lixeira.');
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Protocolo', 'Avulso', '.pdf', (string)$copy['sha256'], $this->root] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $again = $service->restore($this->admin, $reason);
        self::assertSame([0, 1], [$again['count'], $again['skipped_duplicate']], 'Rodar de novo não muda nada.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $service->restore($limited, $reason);
            self::fail('Restauração fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function deletedAt(int $document): ?string
    {
        $value = $this->pdo->query("SELECT deleted_at FROM erp_documents WHERE id = {$document}")->fetchColumn();
        return $value === null || $value === false ? null : (string)$value;
    }

    private function upload(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return $this->disk->upload($this->admin, ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)],
            ['condominium_id' => $this->condominium, 'folder' => 'outros']);
    }

    private function pouch(int $document): void
    {
        $this->pdo->prepare("INSERT INTO erp_pouches (condominium_id, sent_on, courier, documents_count, contents, document_id) VALUES (?, ?, 'Portador de teste', 1, 'Teste', ?)")
            ->execute([$this->condominium, date('Y-m-d'), $document]);
    }
}
