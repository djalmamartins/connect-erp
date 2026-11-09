<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Services\Erp\Antivirus\UploadScanner;
use Source\Services\Erp\Antivirus\UploadScanPolicy;
use Source\Services\Erp\Antivirus\UploadScanUnavailable;
use Source\Services\Erp\DocumentVerification;
use Source\Services\Erp\DocumentVerificationThrottle;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitDocuments;
use Source\Services\Erp\VirtualDisk;

/**
 * ADR 0098 no MySQL: verificação pública de documento emitido (autêntico, cancelado, limite e nada de dado
 * pessoal na resposta) e o antivírus no upload do Disco Virtual (scan_status gravado; recusado não é guardado).
 */
final class DocumentVerificationIntegrationTest extends TestCase
{
    private const IP = '198.51.100.30';
    private int $alpha;
    private User $admin;
    private string $root;
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM auth_throttle_events');
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Residencial Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user($this->alpha);
        $this->root = sys_get_temp_dir() . '/dv-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/dv-work-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0700, true);
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

    public function testAuthenticRevokedLimitAndNoPersonalData(): void
    {
        $registry = new RegistryService($this->pdo);
        $unit = $registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '804', 'type' => 'apartamento'])['id'];
        $documents = new UnitDocuments($this->pdo);
        $certificate = $documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $unit, 'as_of' => date('Y-m-d')]);
        $service = new DocumentVerification($this->pdo);

        $ok = $service->check(strtolower($certificate['verification_display']), self::IP);
        self::assertTrue($ok['ok']);
        self::assertSame(['kind' => 'Certidão de Débitos', 'issued_on' => date('d/m/Y'), 'condominium' => 'Residencial Alpha'], $ok['document']);
        $flat = (string)json_encode($ok, JSON_UNESCAPED_UNICODE);
        foreach (['804', 'clear', 'Nada consta', (string)$certificate['id'], $certificate['verification_code']] as $private) {
            self::assertStringNotContainsString($private, $flat, 'Vazou: ' . $private);
        }

        $documents->cancel($this->admin, $certificate['id'], 'Emitida com data errada');
        $generic = ['ok' => false, 'message' => DocumentVerification::GENERIC, 'document' => null];
        self::assertSame($generic, $service->check($certificate['verification_code'], self::IP), 'Cancelado = mensagem genérica.');
        self::assertSame($generic, $service->check('TUVW-XYZ2-3456-789A', self::IP), 'Inexistente = a mesma mensagem.');

        for ($i = 2; $i < DocumentVerificationThrottle::MAX_FAILURES; $i++) {
            $service->check('TUVW-XYZ2-3456-789A', self::IP);
        }
        self::assertSame(DocumentVerification::THROTTLED, $service->check($certificate['verification_code'], self::IP)['message']);
        $hashes = $this->pdo->query("SELECT DISTINCT subject_hash FROM auth_throttle_events WHERE scope = 'doc_verify'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([DocumentVerificationThrottle::subject(self::IP)], $hashes, 'Só o HMAC do IP fica gravado.');

        $this->pdo->exec('RENAME TABLE auth_throttle_events TO auth_throttle_events_off');   // só no banco de teste
        try {
            self::assertSame(DocumentVerification::UNAVAILABLE, $service->check($certificate['verification_code'], '198.51.100.31')['message'], 'Falha fechada.');
        } finally {
            $this->pdo->exec('RENAME TABLE auth_throttle_events_off TO auth_throttle_events');
        }
    }

    public function testUploadRecordsScanStatusAndRefusedFilesAreNotStored(): void
    {
        $off = new VirtualDisk($this->pdo, null, $this->root, true, new UploadScanPolicy('off'));
        self::assertSame([ScanStatus::UNSCANNED, null], $this->statusOf($off->upload($this->admin, $this->file('a.txt', 'ata 1'), $this->input())));

        $clean = new VirtualDisk($this->pdo, null, $this->root, true, new UploadScanPolicy('enforce', self::scanner(ScanStatus::CLEAN)));
        $stored = $clean->upload($this->admin, $this->file('b.txt', 'ata 2'), $this->input());
        self::assertSame(ScanStatus::CLEAN, $stored['scan_status']);
        self::assertNotNull($stored['scanned_at']);

        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents')->fetchColumn();
        foreach ([[ScanStatus::INFECTED, InvalidArgumentException::class], [ScanStatus::ERROR, UploadScanUnavailable::class]] as [$status, $exception]) {
            $disk = new VirtualDisk($this->pdo, null, $this->root, true, new UploadScanPolicy('enforce', self::scanner($status)));
            try {
                $disk->upload($this->admin, $this->file('c.txt', 'ata ' . $status), $this->input());
                self::fail('Guardou arquivo com status ' . $status);
            } catch (\Throwable $error) {
                self::assertInstanceOf($exception, $error);
            }
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents')->fetchColumn(), 'Recusado não gera registro.');
        self::assertCount(2, glob($this->root . '/' . $this->alpha . '/*/*') ?: [], 'Nem arquivo na pasta privada.');
    }

    /** @return array{0:string, 1:?string} */
    private function statusOf(array $document): array
    {
        return [$document['scan_status'], $document['scanned_at']];
    }

    private static function scanner(string $status): UploadScanner
    {
        return new class($status) implements UploadScanner {
            public function __construct(private string $status) {}
            public function scan(string $path): string { return $this->status; }
            public function available(): bool { return true; }
        };
    }

    private function input(): array
    {
        return ['condominium_id' => $this->alpha, 'folder' => 'atas'];
    }

    private function file(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function user(int $condominium): User
    {
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $condominium);
        return (new User())->findById($id);
    }
}
