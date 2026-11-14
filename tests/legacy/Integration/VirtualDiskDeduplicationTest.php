<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VirtualDisk;
use Source\Services\Erp\VirtualDiskDeduplication;

/** ADR 0275: cópias repetidas para a lixeira pela exclusão lógica do Disco Virtual, tudo ou nada; o arquivo físico fica. */
final class VirtualDiskDeduplicationTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/vdd-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/vdd-work-' . bin2hex(random_bytes(4));
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

    public function testTrashesCopiesKeepsTheOldestAndTheFiles(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $keep = $this->upload('Ata da assembleia.pdf', $pdf, 'atas');
        $copyA = $this->upload('Ata copia contratos.pdf', $pdf, 'contratos');
        $copyB = $this->upload('Ata copia outros.pdf', $pdf, 'outros');
        $single = $this->upload('Planta.pdf', $pdf . "% planta\n", 'plantas');
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Limpeza das cópias'];

        $preview = $this->service()->trash($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$copyA['id'], $copyB['id']], [$keep['id'], $keep['id']], 1], [$preview['dry_run'], $preview['changed'],
            array_column($preview['documents'], 'id'), array_column($preview['documents'], 'kept_id'), $preview['groups']]);
        self::assertSame(0, $this->trashed(), 'dry_run não grava.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'delete'")->fetchColumn(),
            'A auditoria da exclusão da prévia também é desfeita.');

        $done = $this->service()->trash($this->admin, $input);
        self::assertSame([2, (int)$copyA['size_bytes'] * 2], [$done['changed'], $done['bytes']]);
        self::assertSame(2, $this->trashed());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_documents WHERE id IN ({$keep['id']}, {$single['id']}) AND deleted_at IS NULL")->fetchColumn(),
            'A cópia que fica e o arquivo sem cópia continuam fora da lixeira.');
        foreach ([$copyA, $copyB] as $copy) {
            $path = (string)$this->pdo->query("SELECT path FROM erp_documents WHERE id = {$copy['id']}")->fetchColumn();
            self::assertFileExists($this->root . '/' . $path, 'O arquivo físico continua guardado.');
        }
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'delete'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_documents' AND action = 'trash_duplicate'")->fetchColumn());
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Ata da assembleia', 'copia', '.pdf', (string)$keep['sha256'], $this->root] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, $this->service()->trash($this->admin, $input)['count'], 'Sem cópias, o segundo lote sai vazio.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $keep = $this->upload('Ata.pdf', $pdf, 'atas');
        $copy = $this->upload('Ata outra pasta.pdf', $pdf, 'outros');
        try {
            $this->service()->trash($this->admin, ['condominium_id' => $this->condominium, 'document_ids' => [$copy['id'], $keep['id']], 'reason' => 'Limpeza das cópias']);
            self::fail('A cópia que fica entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$keep['id'], $exception->errors['_document']);
        }
        self::assertSame(0, $this->trashed(), 'Nada foi para a lixeira.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $this->service()->trash($limited, ['condominium_id' => $this->condominium, 'reason' => 'Limpeza das cópias']);
            self::fail('Lote fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testSharedWithResidentsCopyStaysOut(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $keep = $this->upload('Ata.pdf', $pdf, 'atas');
        $shared = $this->upload('Ata outra pasta.pdf', $pdf, 'outros');
        $this->pdo->prepare('UPDATE erp_documents SET shared_with_residents = 1 WHERE id = ?')->execute([$shared['id']]);
        $done = $this->service()->trash($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Limpeza das cópias']);
        self::assertSame([0, 1], [$done['count'], $done['skipped_linked']], 'A cópia compartilhada com os moradores fica (ADR 0302).');
        try {
            $this->service()->trash($this->admin, ['condominium_id' => $this->condominium, 'document_ids' => [$shared['id']], 'reason' => 'Limpeza das cópias']);
            self::fail('A cópia compartilhada entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$shared['id'], $exception->errors['_document']);
        }
        self::assertSame(0, $this->trashed());
        self::assertArrayHasKey('erp_entry_attachments', VirtualDiskDeduplication::LINKS, 'Anexo de lançamento (ADR 0174) protege a cópia.');
        unset($keep);
    }

    private function service(): VirtualDiskDeduplication
    {
        return new VirtualDiskDeduplication($this->pdo, null, $this->disk);
    }

    private function upload(string $name, string $content, string $folder): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return $this->disk->upload($this->admin, ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)],
            ['condominium_id' => $this->condominium, 'folder' => $folder]);
    }

    private function trashed(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_documents WHERE condominium_id = {$this->condominium} AND deleted_at IS NOT NULL")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
