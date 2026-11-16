<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;

/** O mesmo arquivo em várias contas de uma vez (Nova Receita com anexos, modelo 8, ADR 0323). */
final class EntryAttachmentsManyTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private string $root;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->admin = (new User())->findById($id);
        $this->service = new RegistryService($this->pdo);
        $this->root = sys_get_temp_dir() . '/eam-root-' . bin2hex(random_bytes(4));
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('1.01', 'Taxa', 'receita', 'analitica')")->execute();
        $this->account = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testOneFileLinkedToEveryEntryWithASingleCopy(): void
    {
        $entries = [];
        foreach (['2026-11', '2026-12', '2027-01'] as $month) {
            $entries[] = $this->service->save($this->admin, 'receivables', ['condominium_id' => $this->alpha, 'chart_account_id' => $this->account, 'amount_cents' => '100,00',
                'competence' => $month, 'due_on' => $month . '-10', 'description' => 'Rateio de obra', 'status' => 'active'])['id'];
        }
        $attachments = new EntryAttachments($this->pdo, null, new VirtualDisk($this->pdo, null, $this->root, true));
        $result = $attachments->attachMany($this->admin, $entries, $this->upload('orcamento.png'), 'outro');
        self::assertSame([3, 0], [$result['attached'], $result['skipped']]);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents WHERE condominium_id = ' . $this->alpha)->fetchColumn(), 'Uma cópia só.');
        foreach ($entries as $entry) {
            self::assertSame([$result['document_id']], array_column($attachments->list($this->admin, $entry), 'document_id'));
        }

        // De novo: o primeiro recusa (já anexado); nada muda nos outros.
        $this->expectExceptionSafely(fn () => $attachments->attachMany($this->admin, $entries, $this->upload('orcamento.png'), 'outro'), \DomainException::class);
        $this->expectExceptionSafely(fn () => $attachments->attachMany($this->admin, [], $this->upload('x.png'), 'outro'), InvalidArgumentException::class);

        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $beta = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, status) VALUES (?, 'receita', ?, 'Outro', 100, '2026-11', '2026-11-10', 'active')")
            ->execute([$beta, $this->account]);
        $foreign = (int)$this->pdo->lastInsertId();
        $this->expectException(RegistryNotFound::class);
        $attachments->attachMany($this->admin, [$entries[0], $foreign], $this->upload('outro.png'), 'outro');
    }

    private function expectExceptionSafely(callable $call, string $class): void
    {
        try {
            $call();
            self::fail("Esperava {$class}.");
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
        }
    }

    private function upload(string $name): array
    {
        $tmp = (string)tempnam(sys_get_temp_dir(), 'png');
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $tmp);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK];
    }
}
