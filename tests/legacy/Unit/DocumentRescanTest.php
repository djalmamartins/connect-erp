<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Antivirus\DocumentRescan;
use Source\Services\Erp\Antivirus\NullScanner;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Services\Erp\Antivirus\UploadScanner;
use Source\Services\Erp\IntegrationStatus;

/** Reverificação dos arquivos do Disco Virtual (ADR 0161), em SQLite em memória e numa pasta temporária. */
final class DocumentRescanTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private PDO $pdo;
    private string $root;
    private string $lock;
    /** @var list<array<string,int>> */
    private array $audits = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, path TEXT, original_name TEXT,
            scan_status TEXT NOT NULL DEFAULT \'unscanned\', scanned_at TEXT, deleted_at TEXT)');
        $this->root = sys_get_temp_dir() . '/rescan-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/1/2026', 0700, true);
        $this->lock = $this->root . '.lock';
        $this->audits = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/1/2026/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root . '/1/2026');
        @rmdir($this->root . '/1');
        @rmdir($this->root);
        @unlink($this->lock);
    }

    public function testEmptyBatchDoesNothing(): void
    {
        self::assertSame(['status' => 'done', 'checked' => 0, 'clean' => 0, 'infected' => 0, 'error' => 0], $this->rescan(self::scanner([]))->run(self::NOW));
        self::assertSame([], $this->audits, 'Sem arquivo verificado, sem auditoria.');
    }

    public function testModeOffAndUnavailableScannerMarkNothing(): void
    {
        $this->file('unscanned');
        self::assertSame('off', $this->rescan(self::scanner([]), 'off')->run(self::NOW)['status']);
        self::assertSame('off', $this->rescan(self::scanner([]), '')->run(self::NOW)['status'], 'Variável vazia = off.');
        self::assertSame('unavailable', $this->rescan(new NullScanner())->run(self::NOW)['status']);
        $failing = self::scanner([ScanStatus::ERROR]);
        self::assertSame(['status' => 'interrupted', 'checked' => 0, 'clean' => 0, 'infected' => 0, 'error' => 0], $this->rescan($failing)->run(self::NOW), 'Falha do scanner para o lote.');
        self::assertSame([['unscanned', null]], $this->statuses(), 'Nada foi marcado.');
        self::assertSame([], $this->audits);
    }

    public function testCleanInfectedAndMissingFilesAreMarkedAndAudited(): void
    {
        $clean = $this->file('unscanned');
        $infected = $this->file('unscanned', 'EICAR');
        $this->pdo->exec("INSERT INTO erp_documents (condominium_id, path, original_name, scan_status) VALUES (1, '1/2026/" . str_repeat('ab', 16) . ".pdf', 'Sumiu.pdf', 'unscanned')");
        $result = $this->rescan(self::scanner([ScanStatus::CLEAN, ScanStatus::INFECTED]))->run(self::NOW);
        self::assertSame(['status' => 'done', 'checked' => 3, 'clean' => 1, 'infected' => 1, 'error' => 1], $result);
        $stamp = date('Y-m-d H:i:s', self::NOW);
        self::assertSame([['clean', $stamp], ['infected', $stamp], ['error', $stamp]], $this->statuses());
        self::assertSame([['clean' => 1, 'infected' => 1, 'error' => 1]], $this->audits, 'Um resumo por lote.');
        $json = (string)json_encode($this->audits);
        self::assertSame([1, 2], [$clean, $infected]);
        self::assertSame(['clean', 'infected', 'error'], array_keys($this->audits[0]), 'Só as três contagens, sem id de arquivo.');
        foreach (['Sumiu', '.pdf', '1/2026', 'Ata', 'path'] as $leak) {
            self::assertStringNotContainsString($leak, $json, 'A auditoria não leva nome nem caminho: ' . $leak);
        }
        self::assertSame(0, $this->rescan(self::scanner([]))->run(self::NOW + 60)['checked'], 'Erro recente espera 24 h.');
        self::assertSame(1, $this->rescan(self::scanner([ScanStatus::CLEAN]))->run(self::NOW + DocumentRescan::RETRY_AFTER + 1)['checked'], 'Depois de 24 h, tenta de novo.');
    }

    public function testBatchLimitAndSkippedStatuses(): void
    {
        for ($i = 0; $i < DocumentRescan::BATCH + 5; $i++) {
            $this->file('unscanned');
        }
        $this->file('clean');
        $this->file('infected');
        self::assertSame(DocumentRescan::BATCH, $this->rescan(self::scanner(array_fill(0, 60, ScanStatus::CLEAN)))->run(self::NOW)['checked']);
        self::assertSame(5, $this->rescan(self::scanner(array_fill(0, 60, ScanStatus::CLEAN)))->run(self::NOW)['checked'], 'O resto vai no lote seguinte.');
        self::assertSame(0, $this->rescan(self::scanner([]))->run(self::NOW)['checked'], 'clean e infected não voltam.');
    }

    public function testLockPreventsConcurrentRuns(): void
    {
        $this->file('unscanned');
        $held = fopen($this->lock, 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
        try {
            self::assertSame('locked', $this->rescan(self::scanner([ScanStatus::CLEAN]))->run(self::NOW)['status']);
            self::assertSame([['unscanned', null]], $this->statuses());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        self::assertSame('done', $this->rescan(self::scanner([ScanStatus::CLEAN]))->run(self::NOW)['status'], 'Solta a trava, roda.');
        self::assertSame('done', $this->rescan(self::scanner([]))->run(self::NOW)['status'], 'A trava é solta no fim de cada lote.');
    }

    public function testIntegrationsShowOnlyNumbers(): void
    {
        $status = IntegrationStatus::antivirus('enforce', true, ['clean' => '7', 'unscanned' => 3, 'error' => 1, 'outro' => 99, 'infected' => -2]);
        self::assertSame(['mode' => 'enforce', 'scanner_configured' => true, 'documents' => ['clean' => 7, 'infected' => 0, 'unscanned' => 3, 'error' => 1],
            'pending_rescan' => 4], $status);
        self::assertSame('off', IntegrationStatus::antivirus('', false, [])['mode']);
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/IntegrationStatus.php');
        self::assertStringNotContainsString("getenv('ERP_AV_ENDPOINT')", $source, 'O endereço do antivírus não aparece.');
        self::assertStringContainsString('deleted_at IS NULL AND condominium_id IN (', $source, 'Contagem só do escopo.');
    }

    private function rescan(UploadScanner $scanner, string $mode = 'enforce'): DocumentRescan
    {
        return new DocumentRescan($this->pdo, $mode, $scanner, $this->root, function (array $summary): void {
            $this->audits[] = $summary;
        }, $this->lock);
    }

    private function file(string $status, string $content = 'ata'): int
    {
        $relative = '1/2026/' . bin2hex(random_bytes(16)) . '.txt';
        file_put_contents($this->root . '/' . $relative, $content);
        $this->pdo->prepare('INSERT INTO erp_documents (condominium_id, path, original_name, scan_status) VALUES (1, ?, ?, ?)')->execute([$relative, 'Ata.txt', $status]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array{0:string, 1:?string}> */
    private function statuses(): array
    {
        return array_map(static fn (array $r): array => [(string)$r['scan_status'], $r['scanned_at']],
            $this->pdo->query('SELECT scan_status, scanned_at FROM erp_documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $results respostas em ordem; acabou = error */
    private static function scanner(array $results): UploadScanner
    {
        return new class($results) implements UploadScanner {
            public function __construct(private array $results) {}
            public function scan(string $path): string { return array_shift($this->results) ?? ScanStatus::ERROR; }
            public function available(): bool { return true; }
        };
    }
}
